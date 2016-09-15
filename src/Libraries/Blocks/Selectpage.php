<?php namespace CoasterCms\Libraries\Blocks;

use CoasterCms\Models\BlockSelectOption;
use CoasterCms\Models\Page;

class Selectpage extends Select
{

    public function edit($content)
    {
        $parent = BlockSelectOption::where('block_id', '=', $this->_block->id)->where('option', '=', 'parent')->first();
        $parentPageId = !empty($parent) ? $parent->value : 0;
        $this->_editExtraViewData['selectOptions'] = [0 => '-- No Page Selected --'] + Page::get_page_list(['parent' => $parentPageId]);
        return parent::edit($content);
    }

}