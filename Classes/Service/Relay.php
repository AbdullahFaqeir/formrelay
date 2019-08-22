<?php

namespace Mediatis\Formrelay\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Michael Vöhringer (Mediatis AG) <voehringer@mediatis.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Mediatis\Formrelay\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException;
use TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException;
use Mediatis\Formrelay\Utility\FormrelayUtility;
use Mediatis\Formrelay\ConfigurationResolver\Evaluation\GateEvaluation;

class Relay implements SingletonInterface
{
    const SIGNAL_REGISTER = 'register';

    const SIGNAL_BEFORE_GATE_EVALUATION = 'beforeGateEvaluation';
    const SIGNAL_AFTER_GATE_EVALUATION = 'afterGateEvaluation';
    const SIGNAL_BEFORE_DATA_MAPPING = 'beforeDataMapping';
    const SIGNAL_AFTER_DATA_MAPPING = 'afterDataMapping';
    const SIGNAL_DISPATCH = 'dispatch';

    const SIGNAL_ADD_DATA = 'addData';

    /** @var ObjectManager */
    protected $objectManager;

    /** @var Dispatcher */
    protected $signalSlotDispatcher;

    /** @var ConfigurationManager */
    protected $configurationManager;

    /** @var DataMapper */
    protected $dataMapper;

    /** @var array */
    protected $settings;

    public function injectObjectManager(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    public function injectSignalSlotDispatcher(Dispatcher $signalSlotDispatcher)
    {
        $this->signalSlotDispatcher = $signalSlotDispatcher;
    }

    public function injectConfigurationManager(ConfigurationManager $configurationManager)
    {
        $this->configurationManager = $configurationManager;
    }

    public function injectDataMapper(DataMapper $dataMapper)
    {
        $this->dataMapper = $dataMapper;
    }

    /**
     * @param array $data The original field array
     * @param bool|array $formSettings
     * @param bool $simulate
     *
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     */
    public function process($data, $formSettings = [], $simulate = false)
    {
        // register form overwrite settings
        $this->configurationManager->setFormrelaySettingsOverwrite($formSettings);

        // fetch own configuration
        if (!$this->settings) {
            $typoScript = $this->configurationManager->getExtensionTypoScriptSetup('tx_formrelay');
            $this->settings = $typoScript['settings'];
        }

        if (!$simulate) {
            // call data providers
            $this->signalSlotDispatcher->dispatch(__CLASS__, static::SIGNAL_ADD_DATA, [&$data]);
            // log form submit
            $this->logData($data);
        }

        // call data processor for all extensions
        $extensionList = [];
        $extensionList = $this->signalSlotDispatcher->dispatch(__CLASS__, static::SIGNAL_REGISTER, [$extensionList])[0];
        $dispatched = false;
        foreach ($extensionList as $extKey) {
            if ($this->processData($data, $extKey)) {
                $dispatched = true;
            }
        }
        if (!$dispatched) {
            // @TODO what to do if no endpoint had been triggered?
        }
    }

    /**
     * @param array $data The original field array
     * @param string $extKey The key of the extenstion which should be processed next
     * @return bool
     *
     * @throws InvalidSlotException
     * @throws InvalidSlotReturnException
     */
    public function processData($data, $extKey)
    {
        $dispatched = false;
        for ($index = 0; $index < $this->configurationManager->getFormrelaySettingsCount($extKey); $index++) {

            // all relevant data for the signal slots (and for processing)
            $signal = [
                null,                                                               // 0: result
                $data,                                                              // 1: data
                $this->configurationManager->getFormrelaySettings($extKey, $index), // 2: conf
                ['extKey' => $extKey, 'index' => $index]                            // 3: context
            ];

            // evaluate gate
            $signal[0] = null;
            $signal = $this->signalSlotDispatcher->dispatch(__CLASS__, static::SIGNAL_BEFORE_GATE_EVALUATION, $signal);
            if ($signal[0] === null) {
                $gateEvaluation = $this->objectManager->get(GateEvaluation::class, $signal[3]);
                $signal[0] = $gateEvaluation->eval(['data' => $signal[1]]);
            }
            $signal = $this->signalSlotDispatcher->dispatch(__CLASS__, static::SIGNAL_AFTER_GATE_EVALUATION, $signal);
            if (!$signal[0]) {
                continue;
            }

            // data mapping
            $signal[0] = null;
            $signal = $this->signalSlotDispatcher->dispatch(__CLASS__,static::SIGNAL_BEFORE_DATA_MAPPING, $signal);
            if ($signal[0] === null) {
                $signal[1] = $this->dataMapper->process($signal[1], $signal[3]['extKey'], $signal[3]['index']);
            }
            $signal = $this->signalSlotDispatcher->dispatch(__CLASS__, static::SIGNAL_AFTER_DATA_MAPPING, $signal);

            // dispatch
            $signal[0] = null;
            $signal = $this->signalSlotDispatcher->dispatch(__CLASS__, static::SIGNAL_DISPATCH, $signal);
            if ($signal[0]) {
                $dispatched = true;
            }
        }
        return $dispatched;
    }

    protected function logData($data = false, $error = false)
    {
        $logfileBase = $this->settings['logfile']['basePath'];

        // Only write a logfile if path is set in TS Config and logdata is not empty
        if (strlen($logfileBase) > 0) {
            $logfilePath = $logfileBase . DIRECTORY_SEPARATOR . $this->settings['logfile']['system'] . '.xml';

            $xmlLog = simplexml_load_string("<?xml version=\"1.0\" encoding=\"UTF-8\"?><log />");
            $xmlLog->addAttribute('type', $error ? 'error' : 'notice');
            $xmlLog->addChild('logdate', date('r'));
            $xmlLog->addChild('userIP', \Mediatis\Formrelay\Utility\IpAddress::getUserIpAdress());

            if ($data) {
                $xmlFields = $xmlLog->addChild('form');
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        $value = implode(',', $value);
                    }
                    $xmlField = $xmlFields->addChild('field', FormrelayUtility::xmlentities($value));
                    $xmlField->addAttribute('name', FormrelayUtility::xmlentities($key));
                }
            }

            $logdata = $xmlLog->asXML();

            // open logfile and place cursor at the end of file
            if ($logfile = fopen($logfilePath, "a")) {
                // write xml to logfile and close it
                @fwrite($logfile, $logdata);
                fclose($logfile);
            } else {
                if (!is_writable($logfilePath)) {
                    GeneralUtility::devLog("logfile is not writeable", __CLASS__, 0, $logfilePath);
                }
                GeneralUtility::devLog("error: ", __CLASS__, 0, error_get_last());
            }
        }
    }

}

