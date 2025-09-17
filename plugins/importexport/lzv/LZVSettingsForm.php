<?php

/**
 * @file plugins/importexport/doaj/classes/form/LZVSettingsForm.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LZVSettingsForm
 *
 * @brief Form for journal managers to setup DOAJ plugin
 */

namespace APP\plugins\importexport\lzv;

use APP\core\Application;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class LZVSettingsForm extends Form
{


    //
    // Constructor
    //
    /**
     * Constructor
     *
     * @param \PKP\plugins\Plugin $plugin
     * @param int $contextId
     */
    public function __construct(public LZVExportPlugin $plugin)
    {

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        // Add form validation checks.
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }


    //
    // Implement template methods from Form
    //
    /**
     * Load settings already saved in the database
     *
     * Settings are stored by context, so that each journal, press,
     * or preprint server can have different settings.
     */
    public function initData()
    {
        $context = Application::get()
            ->getRequest()
            ->getContext();

        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData(
                $fieldName,
                $this->plugin->getSetting(
                    $context->getId(),
                    $fieldName
                )
            );
        }

        
        parent::initData();
    }

    /**
     * Load data that was submitted with the form
     */
    public function readInputData()
    {
        $this->readUserVars(array_keys($this->getFormFields()));

        parent::readInputData();

    }

    /**
     * Fetch any additional data needed for your form.
     *
     * Data assigned to the form using $this->setData() during the
     * initData() or readInputData() methods will be passed to the
     * template.
     *
     * In the example below, the plugin name is passed to the
     * template so that it can be used in the URL that the form is
     * submitted to.
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $context = Application::get()
            ->getRequest()
            ->getContext();
        
        
        //$plugin = $this->_getPlugin();
        //$contextId = $this->_getContextId();

        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->plugin->updateSetting(
                $context->getId(),
                $fieldName, 
                $this->getData($fieldName), 
                $fieldType
            );
        }

            $notificationMgr = new NotificationManager();
            $notificationMgr->createTrivialNotification(
                Application::get()->getRequest()->getUser()->getId(),
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('common.changesSaved')]
            );
            

        return parent::execute();
    }


    //
    // Public helper methods
    //
    /**
     * Get form fields
     *
     * @return array (field name => field type)
     */
    public function getFormFields()
    {
        return [
            'testString' => 'string',
            'testBoolOne' => 'bool',
            'testBoolTwo' => 'bool'
        ];
    }

    /**
     * Is the form field optional
     *
     * @param string $settingName
     *
     * @return bool
     */
    public function isOptional($settingName)
    {
        return in_array($settingName, ['testString', 'testBoolOne', 'testBoolTwo']);
    }
}


