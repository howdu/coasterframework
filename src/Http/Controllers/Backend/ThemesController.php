<?php namespace CoasterCms\Http\Controllers\Backend;

use CoasterCms\Helpers\BlockManager;
use CoasterCms\Libraries\Builder\ThemeBuilder;
use CoasterCms\Models\Block;
use CoasterCms\Models\BlockBeacon;
use CoasterCms\Models\BlockCategory;
use CoasterCms\Models\BlockFormRule;
use CoasterCms\Models\BlockSelectOption;
use CoasterCms\Models\Theme;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

class ThemesController extends _Base
{

    public function getIndex()
    {
        $themes = [];

        $themesPath = base_path('resources/views/themes');
        if (is_dir($themesPath)) {
            foreach (Theme::all() as $databaseTheme) {
                $databaseThemes[$databaseTheme->theme] = $databaseTheme;
            }

            foreach (scandir($themesPath) as $themeFolder) {
                if (is_dir($themesPath . '/' . $themeFolder) && strpos($themeFolder, '.') !== 0) {
                    if (isset($databaseThemes[$themeFolder])) {
                        $themes[$databaseThemes[$themeFolder]->id] = $databaseThemes[$themeFolder]->theme;
                    }
                }
            }
        }

        $blockSettings = [];
        foreach (BlockManager::getBlockClasses() as $blockName => $blockClass) {
            $blockSettingsAction = $blockClass::block_settings_action();
            if (!empty($blockSettingsAction['action']) && Auth::action(str_replace('/', '.', $blockSettingsAction['action']))) {
                $blockSettings[$blockSettingsAction['name']] = $blockSettingsAction['action'];
            }
        }

        $this->layout->content = View::make('coaster::pages.themes', ['themes' => $themes, 'blockSettings' => $blockSettings]);
    }

    public function getManage()
    {
        $this->layout->content = View::make('coaster::pages.themes.manage', ['error' => '', 'themes_count' => Theme::count(), 'themes_installed' => ThemeBuilder::installThumbs()]);
        $this->layout->modals = View::make('coaster::modals.themes.delete_theme');
    }

    public function postManage()
    {
        $request = Request::all();

        if (!empty($request['activate'])) {
            return Theme::activate($request['theme']);
        }

        if (!empty($request['remove'])) {
            return Theme::remove($request['theme']);
        }

        if ($request['newTheme']) {
            $file = Request::file('newTheme');
            $validator = Validator::make(['theme' => $request['newTheme']], ['theme' => 'required']);
            if (!$validator->fails() && $file->getClientOriginalExtension() == 'zip') {
                $uploadTo = base_path() . '/resources/views/themes/';
                if (!is_dir($uploadTo . $file->getClientOriginalName())) {
                    $file->move($uploadTo, $file->getClientOriginalName());
                    $zip = new \ZipArchive;
                    if ($zip->open($uploadTo . $file->getClientOriginalName()) === true) {
                        $filePathInfo = pathinfo($file->getClientOriginalName());
                        mkdir($uploadTo . $filePathInfo['filename']);
                        $zip->extractTo($uploadTo . $filePathInfo['filename'] . '/');
                        $zip->close();
                        return redirect(config('coaster::admin.url') . '/themes/manage')->send();
                    } else {
                        $error = 'Error uploading zip file, file may bigger than the server upload limit.';
                    }
                } else {
                    $error = 'Theme with the same name already exists';
                }
            } else {
                $error = 'Uploaded theme must be a zip file.';
            }
            $this->layout->content = View::make('coaster::pages.themes.manage', ['error' => $error, 'themes_count' => Theme::count(), 'themes_installed' => ThemeBuilder::installThumbs()]);
            $this->layout->modals = View::make('coaster::modals.themes.delete_theme');
        }

        if (!empty($request['install'])) {
            return Theme::install($request['theme']);
        }
    }

    public function getBeacons()
    {
        $this->layout->content = View::make('coaster::pages.themes.beacons', ['rows' => BlockBeacon::getTableRows()]);
    }

    public function postBeacons()
    {
        if ($id = Request::input('add')) {
            BlockBeacon::addId();
            return BlockBeacon::getTableRows();
        }
        if ($id = Request::input('delete_id')) {
            BlockBeacon::removeId($id);
            return 1;
        }
    }

    public function getUpdate($themeId)
    {
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('PageBuilder', 'CoasterCms\Libraries\Builder\ThemeBuilder');

        ThemeBuilder::processFiles($themeId);

        $this->layout->content = View::make('coaster::pages.themes.update',
            [
                'theme' => ThemeBuilder::getThemeName(),
                'blocksData' => ThemeBuilder::getMainTableData(),
                'typeList' => $this->_typeList(),
                'categoryList' => $this->_categoryList(),
                'templateList' => ThemeBuilder::getTemplateList()
            ]
        );

        $loader->alias('PageBuilder', 'CoasterCms\Libraries\Builder\PageBuilder');
    }

    public function postUpdate($themeId)
    {
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('PageBuilder', 'CoasterCms\Libraries\Builder\ThemeBuilder');

        ThemeBuilder::processFiles($themeId);

        // add any new templates
        ThemeBuilder::saveTemplates();

        $blocks = Request::input('block');

        // Update Blocks Table
        foreach ($blocks as $block => $details) {
            // Update Blocks
            ThemeBuilder::saveBlock($block, $details);

            // Update ThemeBlocks/TemplateBlocks
            if (!empty($details['run_template_update'])) {
                ThemeBuilder::updateBlockTemplates($block, $details);
            }
        }

        ThemeBuilder::updateBlockRepeaters();

        $this->layout->content = View::make('coaster::pages.themes.update',
            [
                'theme' => ThemeBuilder::getThemeName(),
                'saved' => true
            ]
        );

        $loader->alias('PageBuilder', 'CoasterCms\Libraries\Builder\PageBuilder');
    }

    public function getForms($template = null)
    {
        if ($template) {
            $rules = BlockFormRule::where('form_template', '=', $template)->get();
            $rules = $rules->isEmpty()?[]:$rules;
            $this->layout->content = View::make('coaster::pages.themes.forms', ['template' => $template, 'rules' => $rules]);
        }
        else {
            $formTemplates = [];
            $themes = base_path('resources/views/themes');
            if (is_dir($themes)) {
                foreach (scandir($themes) as $theme) {
                    if (!is_dir($theme) && $theme != '.' && $theme != '..') {

                        $forms = $themes . DIRECTORY_SEPARATOR . $theme . '/blocks/forms';
                        if (is_dir($forms)) {
                            foreach (scandir($forms) as $form) {
                                if (!is_dir($forms . DIRECTORY_SEPARATOR . $form)) {
                                    $form_file = explode(".", $form);
                                    if (!empty($form_file[0])) {
                                        $formTemplates[] = $form_file[0];
                                    }
                                }
                            }
                        }

                    }
                }
            }
            $this->layout->content = View::make('coaster::pages.themes.forms', ['templates' => $formTemplates]);
        }
    }

    public function postForms($template)
    {
        $databaseRules = [];
        $inputRules = [];

        $rules = BlockFormRule::where('form_template', '=', $template)->get();
        if (!$rules->isEmpty()) {
            foreach ($rules as $rule) {
                $databaseRules[$rule->field] = $rule;
            }
        }

        $rules = Request::get('rule');
        if (!empty($rules)) {
            foreach ($rules as $rule) {
                $inputRules[$rule['field']] = $rule['rule'];
            }
        }

        $toAdd = array_diff_key($inputRules, $databaseRules);
        $toUpdate = array_intersect_key($inputRules, $databaseRules);
        $toDelete = array_diff_key($databaseRules, $inputRules);

        if (!empty($toDelete)) {
            BlockFormRule::where('form_template', '=', $template)->whereIn('field', array_keys($toDelete))->delete();
        }

        if (!empty($toAdd)) {
            foreach ($toAdd as $field => $rule) {
                $newBlockFormRule = new BlockFormRule;
                $newBlockFormRule->form_template = $template;
                $newBlockFormRule->field = $field;
                $newBlockFormRule->rule = $rule;
                $newBlockFormRule->save();
            }
        }

        if (!empty($toUpdate)) {
            foreach ($toUpdate as $field => $rule) {
                if ($rule != $databaseRules[$field]->rule) {
                    $databaseRules[$field]->rule = $rule;
                    $databaseRules[$field]->save();
                }
            }
        }

        return redirect(config('coaster::admin.url').'/themes/forms');
    }

    public function getSelects($block_id = null)
    {
        if ($block_id) {
            $block = Block::where('type', 'LIKE', '%select%')->where('type', 'NOT LIKE', '%selectpage%')->where('id', '=', $block_id)->first();

            if (!empty($block)) {
                $options = BlockSelectOption::where('block_id', '=', $block_id)->get();
                $options = $options->isEmpty()?[]:$options;
                $this->layout->content = View::make('coaster::pages.themes.selects', ['block' => $block, 'options' => $options]);
            }
        }
        else {

            $selectBlocks = [];

            $blocks = Block::where('type', 'LIKE', '%select%')->where('type', 'NOT LIKE', '%selectpage%')->get();
            if (!$blocks->isEmpty()) {
                foreach ($blocks as $block) {
                    $selectBlocks[$block->id] = $block->name;
                }
            }

            $this->layout->content = View::make('coaster::pages.themes.selects', ['blocks' => $selectBlocks]);
        }
    }

    public function postSelects($block_id)
    {
        $inputOptions = [];
        $options = Request::get('selectOption');
        if (!empty($options)) {
            foreach ($options as $option) {
                $inputOptions[$option['value']] = $option['option'];
            }
        }

        BlockSelectOption::import($block_id, $inputOptions);

        return redirect(config('coaster::admin.url').'/themes/selects');
    }

    private function _typeList()
    {
        $selectArray = [];
        foreach (BlockManager::getBlockClasses() as $blockName => $blockClass) {
            $selectArray[$blockName] = $blockName;
        }
        return $selectArray;
    }

    private function _categoryList()
    {
        $blockCategoryNames = [];
        $blockCategories = BlockCategory::orderBy('order')->get();
        foreach ($blockCategories as $blockCategory) {
            $blockCategoryNames[$blockCategory->id] = $blockCategory->name;
        }
        return $blockCategoryNames;
    }

}