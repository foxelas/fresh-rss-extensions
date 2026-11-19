<?php

class EoshLinkFixExtension extends Minz_Extension {

    public function init(): void {
        $this->registerHook('entry_before_insert', [$this, 'fixLink']);
    }

    public function fixLink($entry) {
        $link = $entry->link(true);
        // Only rewrite https://eosh.gr/api/<slug> (a single path part, nothing after it)
        if (preg_match('#^https?://eosh\.gr/api/([^/?\#]+)$#', $link, $m)) {
            $entry->_link('https://eosh.gr/expeditions/' . $m[1]);
        }
        return $entry;
    }
}
