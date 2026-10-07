<?php

namespace App\Services\Imports;

interface ImportMapper
{
    /**
     * Produce inert configuration; never execute/fetch source instructions.
     * Each item contains name, method, path, collection, correlation_key, raw_curl,
     * variant, excluded_query_params, excluded_headers, path_pattern_enabled,
     * contains_secrets, warnings, and responses. Responses use MockResponse's
     * status_code/headers/body/body_mode/weight/external_label attributes.
     * The optional document.variable_candidates inventory contains scoped names, known values, secret defaults and sources. Items may retain variable_provenance
     * and response templates. Public previews remove secret candidate values.
     * No raw script/source object belongs in this document or its preview cache.
     *
     * @return array{type:string,title:string,folders:array,items:array,warnings:array,variable_candidates?:array}
     */
    public function map(string $json, bool $maskSecrets = false): array;
}
