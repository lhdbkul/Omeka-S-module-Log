<?php declare(strict_types=1);

namespace Log\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class JobState extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/job-state';

    /**
     * @var \Log\Stdlib\JobState
     */
    protected $jobState;

    public function __construct(\Log\Stdlib\JobState $jobState)
    {
        $this->jobState = $jobState;
    }

    /**
     * Get the state of a running or stopping job.
     *
     * Windows is not supported (neither in omeka job anyway).
     *
     * Linux states are:
     * - R: Running
     * - S: Interruptible Sleep (Sleep, waiting for event from software)
     * - D: Uninterruptible Sleep (Dead, waiting for signal from hardware)
     * - T: Stopped (Traced)
     * - Z: Zombie
     *
     * Warning: in some cases, the state is not reliable, because it may be the
     * one of another process.
     *
     * With html output, the job status will be managed via js for span with class
     * "log-job-status job-status-label", that may be a link or a simple text.
     *
     * @param \Omeka\Api\Representation\JobRepresentation|\Omeka\Entity\Job|null $job
     * @param array $options Options to get prepare output. Passed to template.
     * - output (string): type of returned string: letter (default) or html
     * - template (string): the template to use for html
     * - skip_css_js (bool): exclude css and js (false by default)
     * @return string|null Letter of the state of the process or null. If option "as_html" is pa
     * Full state can be retrieved from the constant \Log\Stdlib\JobState::STATES.
     *
     * @uses \Log\Stdlib\JobState
     */
    public function __invoke($job, array $options = []): ?string
    {
        $state = $this->jobState->__invoke($job);

        $outputLetter = empty($options['output']) || $options['output'] !== 'html';
        if ($outputLetter) {
            return $state;
        }

        $args = [
            'job' => $job,
            'state' => $state,
            'skipCssJs' => !empty($options['skip_css_js']),
        ];

        $template = empty($options['template']) ? self::PARTIAL_NAME : $options['template'];
        unset($options['output'], $options['template'], $options['skipCssJs']);

        return $this->getView()->partial($template, $args + $options);
    }
}
