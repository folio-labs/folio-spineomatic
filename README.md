# SpineOMatic FOLIO PHP Middleware

These PHP scripts use the FOLIO inventory/items API to search for an item by the barcode scanned/entered into SpineOMatic. If the item is located, the call number is returned to SpineOMatic for parsing and printing. The PHP scripts may be configured to map one or more FOLIO call number types to the types supported in SpineOMatic (LC, Dewey, NLM, etc.). The XML format is a shortened version of that provided in Texas A&M University Libraries' mod-spine-o-matic FOLIO module. The currently available fields are:
* \<title\>
* \<call_number_prefix\>
* \<call_number\>
* \<call_number_type\>
* \<enumeration\>
* \<chronology\>

Additionally, call numbers may be extended with the following fields from FOLIO:
* Item Call Number Suffix
* Item Volume
* Item Copy Number

## Requirements
* Web server with PHP 8.0 or above
* PHP cURL Extension
* PHP JSON Extension

## Setup
1. Copy the public folder or inidivdual files to a system or virtual machine with the above requirements installed.
2. Edit the config.php file to set required and optional parameters. *Instructions are included in the file.*
3. Set read and write permissions for the web server user on the cache.json file if using a FOLIO username and password.
4. Deny access to the config.php and cache.json files through the web server. *See sample .htaccess file.*
5. Configure SpineOMatic to send barcodes to the item.php script.
    1. Click the **Alma Access** tab.
    2. Enter the https:// followed by the domain name or IP address of the web server in the **ALMA URL** field.
    3. Enter /**path to scripts**/item.php?item_barcode={item_barcode} in the **Method** field.

![SpineOMatic ALMA Access Configuration](/resources/som_alma_access.jpg)

6. Configure SpineOMatic call number formats.

![SpineOMatic Call Number Format Configuration](/resources/som_call_number_format.jpg)

7. Scan and print.

## Extended Call Numbers
The **Formatted (Extended) Call Numbers** section of the config.php file allows additional FOLIO fields to be added to the call numbers for SpineOMatic to parse and print. This feature uses a syntax of the field name with two pairs of surrounding curly brackets:

**a** { **b** { **folio_field_name** > **#** < **#**} **b** } **a**
* **a** - Characters outside the outer pair of curly brackets will always be included in the call number.
* **b** - Characters inside the outer pair and outside the inner pair of curly brackets will be included only if the field is not empty in FOLIO.
* **folio_field_name** - The FOLIO field identifier must follow the first of the inner curly brackets with no leading space. *Valid FOLIO field identifiers are in the config.php file.*
* < > = **#** - Optional numeric filters operators follow immediately after the FOLIO field identifier. If included the field, along with any characters between outer and inner curly brackets, will only be included if the first number in the FOLIO field equals (=), is greater than (>), less than (<), or falls in a range specified using both < and >. *Examples are included in the config.php file.*

Walker Library uses this feature with SpineOMatic's option to include breaks before characters in order to include item volume and copy numbers in spine labels.

{{item_call_number}}{ v.{item_volume}}{ c.{item_copy_number>1}}

![SpineOMatic Call Number Format Tweak and Test Configuration](/resources/som_call_number_format_break.jpg)

