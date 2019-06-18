<?php

function parseFilter($filter) {
    $parsedFilter = [];
    $dotRegex = "/([a-zA-Z0-9])__([a-zA-Z])/";
    $dollarRegex = "/^___([a-zA-Z])/";

    foreach ($filter as $key => $value) {
        switch (1) {
            case preg_match($dotRegex, $key):
                $key = preg_replace($dotRegex, '$1.$2', $key);
                break;
            case preg_match($dollarRegex, $key):
                $key = preg_replace($dollarRegex, '\$$1', $key);
                break;
        }

        $parsedFilter[$key] = is_array($value) ? parseFilter($value) : $value;
    }

    return $parsedFilter;
}