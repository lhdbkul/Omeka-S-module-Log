<?php declare(strict_types=1);

namespace Log\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $severityValueOptions = [
            '>0' => 'Emergency', // @translate
            '>1' => 'Alert', // @translate
            '>2' => 'Critical', // @translate
            '>3' => 'Error', // @translate
            '>4' => 'Warning', // @translate
            '>5' => 'Notice', // @translate
            '>6' => 'Info', // @translate
            '>7' => 'Debug', // @translate
        ];

        $this
            ->add([
                'name' => 'log_cron_days',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Archive or delete logs every x days (set 0 if you use the server cron)', // @translate
                ],
                'attributes' => [
                    'id' => 'log_cron_days',
                    'value' => 180,
                    'min' => 0,
                    'step' => 1,
                ],
            ])

            // Filters.

            ->add([
                'name' => 'log_archive_days',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Age of logs to archive and/or delete (days)', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_days',
                    'value' => 180,
                    'min' => 0,
                    'step' => 1,
                ],
            ])
            ->add([
                'name' => 'log_archive_severity_max',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    // The severity max is 0.
                    'label' => 'Minimum severity to keep', // @translate
                    'value_options' => $severityValueOptions,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'log_archive_severity_max',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select minimum severity…', // @translate
                ],
            ])
            ->add([
                'name' => 'log_archive_delete_job_logs',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Delete logs with jobs (except errors)', // @translate
                    'info' => 'When unchecked, logs associated with a job are preserved during archiving and deletion, unless their severity is info or debug.', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_delete_job_logs',
                ],
            ])
            ->add([
                'name' => 'log_archive_references',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'References to keep', // @translate
                    'info' => 'Filter by reference patterns, one per line. Supports wildcards: "easy-admin/check/*" (starts with), "*/process" (ends with), "my/reference" (exact). Leave empty for archive all references.', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_reference',
                    'rows' => 10,
                ],
            ])

            // Store.

            ->add([
                'name' => 'log_archive_store',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Archive old logs', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_store',
                ],
            ])
            ->add([
                'name' => 'log_archive_format',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Archive format', // @translate
                    'value_options' => [
                        'tsv' => 'tsv', // @translate
                        'tsv_context' => 'Tsv with context', // @translate
                        'sql' => 'sql', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'log_archive_format',
                    'value' => 'tsv',
                ],
            ])
            ->add([
                'name' => 'log_archive_compress',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Compress archives (gzip)', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_compress',
                ],
            ])
            ->add([
                'name' => 'log_archive_include_id',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Include log id', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_include_id',
                ],
            ])
            ->add([
                'name' => 'log_archive_translate',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Translate messages (tsv)', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_translate',
                ],
            ])

            // Delete.

            ->add([
                'name' => 'log_archive_delete',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Delete old logs', // @translate
                ],
                'attributes' => [
                    'id' => 'log_archive_delete',
                ],
            ])
        ;
    }
}
