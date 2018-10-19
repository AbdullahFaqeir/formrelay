<?php

/***************************************************************
 * Extension Manager/Repository config file for ext: "formrelay"
 *
 * Auto generated by Extension Builder 2016-10-04
 *
 * Manual updates:
 * Only the data in the array - anything else is removed by next write.
 * "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = [
    'title' => 'Form Relay',
    'description' => 'Send form data to different providers like Salesforce',
    'category' => 'plugin',
    'author' => 'Michael Vöhringer',
    'author_email' => 'voehringer@mediatis.de',
    'author_company' => 'Mediatis AG',
    'state' => 'beta',
    'internal' => '',
    'uploadfolder' => '1',
    'createDirs' => '',
    'clearCacheOnLoad' => 0,
    'version' => '0.2.4.5',
    'constraints' => [
        'depends' => [
            'typo3' => '7.6.0-8.7.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
            'form' => '>=0.0.1',
            'formhandler' => '>=0.0.1',
        ],
    ],
];
