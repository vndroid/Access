<?php

namespace TypechoPlugin\Access;

use Typecho\Common;
use Typecho\Request;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Page
{
    private int $each_disNums;
    private int $nums;
    private int $current_page;
    private int $sub_pages;
    private int $pageNums;
    private array $page_array = [];
    private array $otherParams = [];

    public function __construct(int $each_disNums, int $nums, int $current_page, int $sub_pages, array $otherParams)
    {
        $this->each_disNums = $each_disNums;
        $this->nums = $nums;
        if (!$current_page) {
            $this->current_page = 1;
        } else {
            $this->current_page = $current_page;
        }
        $this->sub_pages = $sub_pages;
        $this->pageNums = ceil($nums / $each_disNums);
        $this->otherParams = $otherParams;
    }

    public function initArray(): array
    {
        for ($i = 0; $i < $this->sub_pages; $i++) {
            $this->page_array[$i] = $i;
        }
        return $this->page_array;
    }

    public function construct_num_Page(): array
    {
        if ($this->pageNums < $this->sub_pages) {
            $current_array = [];
            for ($i = 0; $i < $this->pageNums; $i++) {
                $current_array[$i] = $i + 1;
            }
        } else {
            $current_array = $this->initArray();
            if ($this->current_page <= 3) {
                for ($i = 0; $i < count($current_array); $i++) {
                    $current_array[$i] = $i + 1;
                }
            } elseif ($this->current_page <= $this->pageNums && $this->current_page > $this->pageNums - $this->sub_pages + 1) {
                for ($i = 0; $i < count($current_array); $i++) {
                    $current_array[$i] = ($this->pageNums) - ($this->sub_pages) + 1 + $i;
                }
            } else {
                for ($i = 0; $i < count($current_array); $i++) {
                    $current_array[$i] = $this->current_page - 2 + $i;
                }
            }
        }
        return $current_array;
    }

    public function show(): string
    {
        $str = "";
        if ($this->current_page > 1) {
            $prevPageUrl = $this->buildUrl($this->current_page - 1);
            $str .= '<li><a href="' . $prevPageUrl . '">&laquo;</a></li>';
        }
        $a = $this->construct_num_Page();

        for ($i = 0; $i < count($a); $i++) {
            $s = $a[$i];
            if ($s == $this->current_page) {
                $url = Request::getInstance()->getRequestUrl();
                $str .= '<li class="current"><a href="' . $url . '">' . $s . '</a></li>';
            } else {
                $url = $this->buildUrl($s);
                $str .= '<li><a href="' . $url . '">' . $s . '</a></li>';
            }
        }
        if ($this->current_page < $this->pageNums) {
            $nextPageUrl = $this->buildUrl($this->current_page + 1);
            $str .= '<li><a href="' . $nextPageUrl . '">&raquo;</a></li>';
        }
        return $str;
    }

    private function buildUrl(int $page): string
    {
        return Common::url('extending.php?' . http_build_query(array_merge($this->otherParams, [
            'page' => $page,
        ])), Options::alloc()->adminUrl);
    }
}

