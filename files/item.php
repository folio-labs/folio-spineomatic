<?php
//header('Content-type: text/plain');
header('Content-type: application/xml');

require_once 'config.php';
require_once 'folio_api.php';



### Main ###

// If token not set in config file, get cached or new token.
if ( empty($folio_okapi_token) ) {
  $cacheObj = get_folio_okapi_token();

  if ( !empty($cacheObj->error) ) {
    display_som_response($cacheObj->error);
  }

  $folio_okapi_token = $cacheObj->token;
}


$item_barcode = filter_input(INPUT_GET, 'item_barcode');

// Respond with error if barcode missing or matches regexp.
if ( empty($item_barcode) ) {
  display_som_response('INPUT ERROR: Empty barcode');
}

if ( !empty($invalid_barcode_regexp) && preg_match($invalid_barcode_regexp, $item_barcode) ) {
  display_som_response('INPUT ERROR: Invalid barcode format');
}


// Set up and send FOLIO API request using PHP cURL extension.
$item_response = get_item_by_barcode($folio_okapi_token, $item_barcode);


// Decode and validate FOLIO response, display SpineOMatic XML.
parse_item_response($item_response['body']);



### Functions ###

function display_som_response($item_title, $item_call_number_prefix = '', $item_call_number = '', $item_call_number_type = '', $enumeration = '', $chronology = '') {

  // Display response in SpineOMatic XML format based on Texas A&M University
  // Libraries mod-spine-o-matic project
  // (https://github.com/TAMULib/mod-spine-o-matic). License notice follows.

  /*
  MIT License

  Copyright (c) 2020 Texas A&M University Libraries

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
   */

  $xml_encode = function($str) {
    return htmlspecialchars($str, ENT_XML1 | ENT_COMPAT, 'UTF-8');
  };
  $encoded_title = $xml_encode($item_title);
  $encoded_item_call_number_prefix = $xml_encode($item_call_number_prefix);
  $encoded_item_call_number = $xml_encode($item_call_number);
  $encoded_item_call_number_type = $xml_encode($item_call_number_type);
  $encoded_enumeration = $xml_encode($enumeration);
  $encoded_chronology = $xml_encode($chronology);

  echo <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<item link="">
  <bib_data>
    <title>{$encoded_title}</title>
  </bib_data>
  <holding_data link="">
    <call_number_prefix>{$encoded_item_call_number_prefix}</call_number_prefix>
    <call_number>{$encoded_item_call_number}</call_number>
    <call_number_type desc="">{$encoded_item_call_number_type}</call_number_type>
  </holding_data>
  <item_data>
    <enumeration>{$encoded_enumeration}</enumeration>
    <chronology>{$encoded_chronology}</chronology>
    <description></description>
    <library desc="-- Library Description --">-- Library Code --</library>
    <location desc="-- Location Name --">-- Location Code --</location>
    <location_gloss></location_gloss>
  </item_data>
</item>
EOF;

  // XML displayed; nothing further.
  exit();
}

function get_item_by_barcode($folio_okapi_token, $item_barcode) {

  global $folio_okapi_tenant;


  // Call FOLIO API to get item with matching barcode.

  $request_headers = [
      "X-Okapi-Tenant: {$folio_okapi_tenant}",
      "X-Okapi-Token: {$folio_okapi_token}",
  ];

  $curl_options = [
      CURLOPT_HTTPGET => true,
      CURLOPT_HTTPHEADER => $request_headers,
  ];

  $response = call_folio_api("/inventory/items?query=(barcode==\"{$item_barcode}\")", $curl_options);


  // Display appropriate error message if API call result not successful.

  switch ($response['status_code']) {
    case 400:
      display_som_response('FOLIO ERROR: Bad request');
      break;
    case 401:
      display_som_response('FOLIO ERROR: Not authorized to perform requested action');
      break;
    case 500:
      display_som_response('FOLIO ERROR: Internal server error');
      break;
  }


  return $response;
}

function parse_numeric_filter($numeric_filter) {

  // Parse numeric filter string into array with operators ad following values.
  $numeric_filter_parts = preg_split('/([<>=])/', $numeric_filter, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
  $count = count($numeric_filter_parts);


  // Only 1 operator and 1 value or 2 operators and 2 values are valid.
  if ( ($count !== 2 ) && ($count !== 4) ) {
    return null;
  }

  // First value must be numeric.
  if (preg_match('/\D/', $numeric_filter_parts[1]) !== 0) {
    return null;
  }


  if ($count === 4) {
    // Equals sign only valid as first operator.
    if ($numeric_filter_parts[2] === '=') {
      return null;
    }

    // Operators cannot be > with > or < with <.
    if ($numeric_filter_parts[0] === $numeric_filter_parts[2]) {
      return null;
    }

    // Second value must be numeric.
    if (preg_match('/\D/', $numeric_filter_parts[3]) !== 0) {
      return null;
    }
  }


  return $numeric_filter_parts;
}

function format_call_number($item_som_call_number_type, $item_fields) {

  global $formatted_call_numbers;

  $format = $formatted_call_numbers[$item_som_call_number_type];
  $formatted_call_number = $format;

  $field_pattern_matches = [ ];


  // Store matches of formatted (extended) call number curly bracket syntax.
  if (preg_match_all('/\{[^{]*\{\w+([<>=]?[^}]*)\}[^{]*\}/', $format, $field_pattern_matches) > 0) {

    // Iterate through pattern match.
    foreach ($field_pattern_matches[0] as $field_pattern_match_index => $field_pattern_match) {

      // Iterate through item fields passed in.
      foreach ($item_fields as $field_name => $field_value) {

        // Check for field's name in pattern matches.
        if ( str_contains($field_pattern_match, "{{$field_name}}") || (preg_match("/\{{$field_name}[<>=][^}]+\}/", $field_pattern_match) === 1) ) {

          $field_filtered = false;
          $field_pattern_replacement = $field_pattern_match;

          // Process numeric filters if set.
          if ( !empty($field_pattern_matches[1][$field_pattern_match_index]) ) {

            $numeric_filter_replacement = '';
            $field_value_number_matches = [ ];

            // Item field value must include a number to proceed.
            if (preg_match('/\d/', $field_value, $field_value_number_matches) === 1) {

              $numeric_filter = $field_pattern_matches[1][$field_pattern_match_index];
              $numeric_filter_parts = parse_numeric_filter($numeric_filter);

              if ( is_null($numeric_filter_parts) ) {
                // Replace filter portion if parsing numeric filter failed.
                $numeric_filter_replacement = ' ERROR';
              } else {

                $field_value_number1_int = (int) $field_value_number_matches[0];

                $numeric_filter_operator1 = $numeric_filter_parts[0];
                $numeric_filter_number1_int = (int) $numeric_filter_parts[1];

                $numeric_filter_operator2 = isset($numeric_filter_parts[2]) ? $numeric_filter_parts[2] : null;
                $numeric_filter_number2_int = isset($numeric_filter_parts[3]) ? (int) $numeric_filter_parts[3] : null;

                if ($numeric_filter_operator1 === '=') {

                  if ($field_value_number1_int !== $numeric_filter_number1_int) {
                    $field_filtered = true;
                  }

                } else if ($numeric_filter_operator1 === '>') {

                  if ( is_null($numeric_filter_operator2) ) {
                    if ($field_value_number1_int <= $numeric_filter_number1_int) {
                      $field_filtered = true;
                    }
                  } else if ( ($field_value_number1_int <= $numeric_filter_number1_int) || ($field_value_number1_int >= $numeric_filter_number2_int) ) {
                    $field_filtered = true;
                  }

                  if ( ($numeric_filter_number1_int === $numeric_filter_number2_int) && ($field_value_number1_int !== $numeric_filter_number1_int ) ) {
                    $field_filtered = false;
                  }

                } else if ($numeric_filter_operator1 === '<') {

                  if ( is_null($numeric_filter_operator2) ) {
                    if ($field_value_number1_int >= $numeric_filter_number1_int) {
                      $field_filtered = true;
                    }
                  } else if ( ($field_value_number1_int >= $numeric_filter_number1_int) || ($field_value_number1_int <= $numeric_filter_number2_int) ) {
                    $field_filtered = true;
                  }

                  if ( ($numeric_filter_number1_int === $numeric_filter_number2_int) && ($field_value_number1_int !== $numeric_filter_number1_int ) ) {
                    $field_filtered = false;
                  }

                }
              }
            }

            $field_pattern_replacement = str_replace($numeric_filter, $numeric_filter_replacement, $field_pattern_replacement);
          } // Item Field Numeric Filter Processing

          if ( $field_filtered || empty($field_value) ) {
            $formatted_call_number = str_replace($field_pattern_match, '', $formatted_call_number);
          } else {
            $field_pattern_replacement = str_replace("{{$field_name}}", $field_value, $field_pattern_replacement);
            $field_pattern_replacement = substr($field_pattern_replacement, 1, strlen($field_pattern_replacement) - 2);

            $formatted_call_number = str_replace($field_pattern_match, $field_pattern_replacement, $formatted_call_number);
          }

        } // Field Name to Pattern Match Check
      } // Item Field Iteration
    } // Field Pattern Iteration
  } // Field Pattern Match


  return $formatted_call_number;
}

function get_som_call_number_type($folio_call_number_type_uuid) {

  global $default_som_call_number_type;
  global $folio_call_number_type_uuids;


  if ( !empty($folio_call_number_type_uuid) ) {
    // Iterate through FOLIO call number type UUIDs set in config file.
    foreach ($folio_call_number_type_uuids as $som_type => $uuids) {
      // Iterate through UUIDS set for each SpineOMatic call number type.
      foreach ($uuids as $uuid) {
        // Return the SOM call number type ($folio_call_number_type_uuids index)
        // for the first matching UUID found.
        if ($uuid === $folio_call_number_type_uuid) {
          return $som_type;
        }
      }
    }
  }


  return $default_som_call_number_type;
}

function parse_item_response($folio_response_json) {

  $response = json_decode($folio_response_json);


  // Run some validations on response.
  if (is_null($response)) {
    // PHP's json_decode function failed.
    display_som_response('PHP ERROR: Item json_decode() failure - ' . json_last_error_msg() );
  } else if (empty($response->items)) {
    // The items array in response does not exist or is empty.
    display_som_response('NOTICE: No matching FOLIO items');
  } else if (!is_array($response->items)) {
    // The items property in reponse is not an array.
    display_som_response('ERROR: Malformed items property');
  } else if (count($response->items) > 1) {
    // More than one item is assigned barcode.
    display_som_response('ERROR: Duplicate item barcodes');
  }


  // Parse JSON and display XML.
  $item = $response->items[0];

  $item_title = $item->title;

  $item_call_number_prefix = empty($item->effectiveCallNumberComponents->prefix) ? null : $item->effectiveCallNumberComponents->prefix;

  $item_som_call_number_type = get_som_call_number_type($item->effectiveCallNumberComponents->typeId);

  $item_call_number = format_call_number(
          $item_som_call_number_type,
          [
              'item_call_number' => $item->effectiveCallNumberComponents->callNumber,
              'item_call_number_suffix' => $item->effectiveCallNumberComponents->suffix,
              'item_copy_number' => $item->copyNumber,
              'item_volume' => $item->volume
          ]
  );

  $item_enumeration = empty($item->enumeration) ? null : $item->enumeration;

  $item_chronology = empty($item->chronology) ? null : $item->chronology;


  display_som_response($item_title, $item_call_number_prefix, $item_call_number, $item_som_call_number_type, $item_enumeration, $item_chronology);
}
