# lzv_plugin: Long time archiving plugin for OJS 3.4.x

## Summary
This is a plugin for OJS 3.4.x that exports articles and their publication process via the command line for archiving in the Rosetta long time archiving format. 

## Description
This plugin was developed as part of the [Langzeitverfügbarkeit Bayern](https://lzv-bayern.de/) project. The aim of this part of the project was to export articles from OJS, along with their publication process and archive them in the archiving software Rosetta.
To this end a custom [medatada schema](https://github.com/ub-regensburg/publication_process) was developed and released on github. Metadata concerning the publication process is collected into an .xml file, according to the schema. All files that correspond to the article, such as manuscripts and reviews are exported into a folder structure and linked inside the metadata xml. 
The whole package is then built into a mets.xml for ingesting in Rosetta.

## Installation
To install the plugin, unpack the "lzv-export" folder into the "/plugins/importexport" folder of your OJS installation. Then restart the server to install.

## Usage
The command line interface tool built into OJS can be opened with: "php /var/www/ojs/tools/importExport.php" 

Dort lässt sich mit dem Kommando "list" eine Liste der Verfügbaren Tools anzeigen, wenn da "LZVExportPlugin" dabei ist, war die Installation erfolgreich.
Using the command "list" shows a list of available Plugins. "LZVExportPlugin" should be part of the list. 

The LZVExportPlugin can then be used via:

Usage:
/var/www/ojs/tools/importExport.php LZVExportPlugin [journal_path] single [articleId1] [articleId2] ... (exports single articles)
/var/www/ojs/tools/importExport.php LZVExportPlugin [journal_path] all (exports all articles in a given journal)
/var/www/ojs/tools/importExport.php LZVExportPlugin [journal_path] overwriteall (exports all articles in a given journal and overwrites existing exports)

The articles are exported into the "data" folder, specified within the config.inc.php file. The articles are put into a subfolder with the name "lzv".

## Authors
This plugin was made as part of the [Langzeitverfügbarkeit Bayern](https://lzv-bayern.de/) project, by Karl-Arnold Bodarwé (karl-arnold.bodarwe@ur.de) and Gernot Deinzer (gernot.deinzer@ur.de)

## License
The project is licensed under a [CC BY 4.0 license](https://creativecommons.org/licenses/by/4.0/)

## Project status
As of 30.9.2025 the project is finished and the plugin will probably not be developed in the future.
