<?php

/**
 * @file plugins/importexport/lzv/LZVExportPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LZVExportPlugin
 *
 * @brief PubMed/MEDLINE XML metadata export plugin
 */

namespace APP\plugins\importexport\lzv;

use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\plugins\ImportExportPlugin;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\core\JSONMessage;
use APP\plugins\importexport\lzv\classes\RosettaExporter;
use PKP\submissionFile\SubmissionFile;
use Illuminate\Support\Facades\DB;
use APP\core\Application;
use Colors\Color;



class LZVExportPlugin extends ImportExportPlugin
{
    public string $exportStatusSettingName = 'lzv_export_status';
    public string $exportDateSettingName = 'lzv_export_date';

    /** @var array Array of possible validation errors */
    private $xmlValidationErrors = [];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        $this->addLocaleData();

        if ($success && $this->getEnabled()) {
            //$this->readyscript();
            return $success;
        }
        
        return false;
    }

    /**
     * Get the name of this plugin. The name must be unique within
     * its category.
     *
     * @return string name of plugin
     */
    public function getName()
    {
        return 'LZVExportPlugin';
    }

    /**
     * Get the display name.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.importexport.lzv.displayName');
    }

    /**
     * Get the display description.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.importexport.lzv.description');
    }

    /**
     * Display the plugin.
     * @param array $args
     * @param \APP\core\Request $request
     */
    public function display($args, $request)
    {
        parent::display($args, $request);
        $templateMgr = TemplateManager::getManager($request);
        $context = $request->getContext();

        switch (array_shift($args)) {
            case 'index':
            case '':
                $apiUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_API, $context->getPath(), 'submissions?status=3');
                $submissionsListPanel = new \APP\components\listPanels\SubmissionsListPanel(
                    'submissions',
                    __('common.publications'),
                    [
                        'apiUrl' => $apiUrl,
                        'count' => 100,
                        'getParams' => new \stdClass(),
                        'lazyLoad' => true,
                    ]
                );
                $submissionsConfig = $submissionsListPanel->getConfig();
                $submissionsConfig['addUrl'] = '';
                $submissionsConfig['filters'] = array_slice($submissionsConfig['filters'], 1);
                $templateMgr->setState([
                    'components' => [
                        'submissions' => $submissionsConfig,
                    ],
                ]);
                $templateMgr->assign([
                    'pageComponent' => 'ImportExportPage',
                ]);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
            case 'exportSubmissions':
                //Magic happens here.

                $submissionIds = (array) $request->getUserVar('selectedSubmissions');
                //$this->exportAllSubmissionFiles($submissionIds);
                //$this->exportAllDiscussions($submissionIds, $context);
                $this->rosettaTestExport($submissionIds, $context);

            case 'exportIssues':
                break;
            default:
                $dispatcher = $request->getDispatcher();
                $dispatcher->handle404();
        }
    }


    public function rosettaTestExport($submissionIds, $context)
    {
        $rosettaExporter = new RosettaExporter();

        $submissions = [];
        foreach ($submissionIds as $submissionId) {
            $submission = Repo::submission()->get($submissionId);
            if ($submission && $submission->getData('contextId') == $context->getId()) {
                $submissions[] = $submission;
            }

        }
        foreach ($submissions as $submissiona) {
            $rosettaExporter->generateSIP($submissiona, $context, false, true);
        }

    }


    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix()
    {
        return 'lzv';
    }


    //NEW: Check public file path from Config
    /**
     * Return the plugin export directory.
     *
     * @return string The export directory path.
     */
    public function getExportPath()
    {
        return Config::getVar('files', 'files_dir') . '/' . $this->getPluginSettingsPrefix() . '/';
    }


       /**
     * Return the whole export file name.
     *
     * @param string $basePath Base path for file storage
     * @param string $submissionFolder Folder of the whole submission
     * @param string $subFolder Folder in which the file shall be placed inside the submission
     * @param string $objectsFileNamePart Part different for each object type.
     * @param string $extension
     * @param ?DateTime $dateFilenamePart
     *
     * @return string
     */
    public function buildExportFileName($basePath, $submissionFolder, $subFolder, $fileName)
    {
        return $basePath . '/' . $this->getPluginSettingsPrefix() . '/' . $submissionFolder . '/' . $subFolder . '/' . $fileName;
    }



    //NEW: get submission Files via Submission ID
    public function getSubmissionFiles($submissionId)
    {

        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->includeDependentFiles()
            ->getMany();

        return $submissionFiles;
    }

    /**
     * Execute import/export tasks using the command-line interface.
     *
     * @param array $args Parameters to the plugin
     */
    public function executeCLI($scriptName, &$args)
    {
        $rosettaExporter = new RosettaExporter();
        //$command = array_shift($args);
        $journalPath = array_shift($args);
        $c = new Color();
        $countExported = 0;
        $countNotExported = 0;


        $contextDao = Application::getContextDAO();

        $context = $contextDao->getByPath($journalPath);

        if (!$context) {
            if ($journalPath != '') {
                
                echo $c(__('plugins.importexport.common.cliError'))->white()->bold()->highlight('red') . PHP_EOL;
                echo $c(__('plugins.importexport.common.error.unknownContext', ['contextPath' => $journalPath]) . "\n\n")->red();
            }
            $this->usage($scriptName);
            return true;
        }

        switch (array_shift($args)) {
            case 'single':
                foreach ($args as $submissionId) {
                    $submission = Repo::submission()->get($submissionId);
                    if(is_null($submission)) {
                        echo $c(__('plugins.importexport.common.cliError'))->white()->bold()->highlight('red') . PHP_EOL;
                        echo $c(__('plugins.importexport.lzv.export.error.articleNotFound', ['articleId' => $submissionId]) . "\n\n")->red();
                    } else {
                        $rosettaExporter->generateSIP($submission, $context, true);
                        echo $c(__('plugins.importexport.lzv.export.articleExported', ['submissionId' => $submissionId]) . "\n")->green();
                        $countExported++;
                    }
                }
                echo $c("\n" . __('plugins.importexport.lzv.export.countExported', ['countExported' => $countExported]) . "\n")->green();
                if($countNotExported > 0) {
                    echo $c("\n" . __('plugins.importexport.lzv.export.countNotExported', ['countExported' => $countNotExported]) . "\n"); 
                }
                return;
            case 'all':
                $submissionDBs = $this->getExportableSubmissions($context);
                foreach ($submissionDBs as $submissionDB) {
                    $submission = Repo::submission()->get($submissionDB->submission_id);
                    if(is_null($submission)) {
                        echo $c(__('plugins.importexport.lzv.export.error.articleNotFound', ['articleId' => $submissionDB->submission_id]) . "\n\n")->red();
                    } else {
                        if (!$rosettaExporter->generateSIP($submission, $context, false)) {
                            echo $c(__('plugins.importexport.lzv.export.articleNotExported', ['submissionId' => $submissionDB->submission_id]) . "\n")->red();
                            $countNotExported++;
                        } else {
                            echo $c(__('plugins.importexport.lzv.export.articleExported', ['submissionId' => $submissionDB->submission_id]) . "\n")->green();
                            $countExported++;
                        }
                    }
                }
                echo $c("\n" . __('plugins.importexport.lzv.export.countExported', ['countExported' => $countExported]) . "\n")->green();
                if($countNotExported > 0) {
                    echo $c("\n" . __('plugins.importexport.lzv.export.countNotExported', ['countExported' => $countNotExported]) . "\n"); 
                }
                return;
            case 'overwriteall':
                $submissionDBs = $this->getExportableSubmissions($context);
                foreach ($submissionDBs as $submissionDB) {
                    $submission = Repo::submission()->get($submissionDB->submission_id);
                    if(is_null($submission)) {
                        echo $c(__('plugins.importexport.lzv.export.error.articleNotFound', ['articleId' => $submissionDB->submission_id]) . "\n\n")->red();
                    } else {
                        if (!$rosettaExporter->generateSIP($submission, $context, true)) {
                            echo $c(__('plugins.importexport.lzv.export.error.articleProblem', ['articleId' => $submissionDB->submission_id]) . "\n")->red();
                            $countNotExported++;
                        } else {
                            echo $c(__('plugins.importexport.lzv.export.articleExported', ['submissionId' => $submissionDB->submission_id]) . "\n")->green();
                            $countExported++;
                        }
                    }
                }
                echo $c("\n" . __('plugins.importexport.lzv.export.countExported', ['countExported' => $countExported]) . "\n")->green();
                if($countNotExported > 0) {
                    echo $c("\n" . __('plugins.importexport.lzv.export.countNotExported', ['countExported' => $countNotExported]) . "\n"); 
                }
                return;
        }

        $this->usage($scriptName);
    }


    /**
     * Display the command-line usage information
     */
    public function usage($scriptName)
    {
        echo __('plugins.importexport.lzv.cliUsage', [
            'scriptName' => $scriptName,
            'pluginName' => $this->getName()
        ]) . "\n";
    }



    /**
     * Add a settings action to the plugin's entry in the
     * plugins list.
     *
     * @param Request $request
     * @param array $actionArgs
     * @return array
     */
    public function getActions($request, $actionArgs)
    {

        // Get the existing actions
        $actions = parent::getActions($request, $actionArgs);

        // Only add the settings action when the plugin is enabled
        if (!$this->getEnabled()) {
            return $actions;
        }

        // Create a LinkAction that will make a request to the
        // plugin's `manage` method with the `settings` verb.
        $router = $request->getRouter();
        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        // Add the LinkAction to the existing actions.
        // Make it the first action to be consistent with
        // other plugins.
        array_unshift($actions, $linkAction);

        return $actions;
    }

    /**
     * Load a form when the `settings` button is clicked and
     * save the form when the user saves it.
     *
     * @param array $args
     * @param Request $request
     * @return JSONMessage
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':

                // Load the custom form
                $form = new LZVSettingsForm($this);

                // Fetch the form the first time it loads, before
                // the user has tried to save it
                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }

                // Validate and save the form data
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
        }
        return parent::manage($args, $request);
    }

    /**
     * Get the mapping between stage names in XML and their numeric consts
     *
     * @return array
     */
    public function getStageNameStageIdMapping()
    {
        return [
            'submission' => SubmissionFile::SUBMISSION_FILE_SUBMISSION,
            'note' => SubmissionFile::SUBMISSION_FILE_NOTE,
            'review_file' => SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
            'review_attachment' => SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
            'final' => SubmissionFile::SUBMISSION_FILE_FINAL,
            'copyedit' => SubmissionFile::SUBMISSION_FILE_COPYEDIT,
            'proof' => SubmissionFile::SUBMISSION_FILE_PROOF,
            'production_ready' => SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
            'attachment' => SubmissionFile::SUBMISSION_FILE_ATTACHMENT,
            'review_revision' => SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
            'dependent' => SubmissionFile::SUBMISSION_FILE_DEPENDENT,
            'query' => SubmissionFile::SUBMISSION_FILE_QUERY,
        ];
    }


    /**
     * Get a Submission id from a publication
     *
     * @param Publication $publication
     * @return int
     */
    public function getSubmissionIdfromPublication($publication)
    {
        $pubID = $publication->getId();
        return DB::table('publications')->select('submission_id')->where('publication_id', $pubID)->get();
    }

    public function getExportableSubmissions($context)
    {
        $results = DB::table('submissions')
        ->where('status', 3)
        ->where('context_id', $context->getId())
        ->get();
        return $results;
    }

    public function readyscript()
    {
        // Get text file contents as array of lines
        $filepath = '../path/file.txt';
        $txt = file($filepath); 
        //check post
        if (isset($_POST["input"]) && 
            isset($_POST["hidden"])) {
            $line = $_POST['hidden'];
            $update = $_POST['input'] . "\n";
            // Make the change to line in array
            $txt[$line] = $update; 
            // Put the lines back together, and write back into txt file
            file_put_contents($filepath, implode("", $txt));
            //success code
            echo 'success';
        } else {
            echo 'error';
        }
    }

}