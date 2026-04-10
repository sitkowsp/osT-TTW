<?php
/**
 * osTicket Ticket Webhook Plugin
 *
 * Fires an HTTP POST request with JSON ticket details whenever a new
 * ticket is created in one of the configured departments.
 *
 * Features:
 *  - Multi-instance: route different departments to different endpoints
 *  - HTTP Basic Auth support
 *  - Department filtering (multiselect or all)
 *  - DNS resolve override for reverse-proxy environments
 *  - Optional debug logging to file
 *
 * Requires: PHP 7.4+, php-curl extension, osTicket >= 1.18
 *
 * @license MIT
 */

require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.dept.php';
require_once INCLUDE_DIR . 'class.ticket.php';


class TicketWebhookPlugin extends Plugin {

    var $config_class = 'TicketWebhookConfig';

    /** Ensures the signal handler is registered only once per request. */
    private static $signalConnected = false;

    /**
     * Called once per enabled instance during osTicket bootstrap.
     * Registers the ticket.created signal handler.
     */
    function bootstrap() {
        if (!self::$signalConnected) {
            Signal::connect('ticket.created',
                array($this, 'onTicketCreated'));
            self::$signalConnected = true;
        }
    }

    /**
     * Signal handler: fires for every new ticket system-wide.
     * Iterates all enabled instances and applies department filters.
     */
    function onTicketCreated($ticket) {
        foreach ($this->getActiveInstances() as $instance) {
            try {
                $this->processInstance($instance, $ticket);
            } catch (\Throwable $e) {
                self::log(
                    sprintf('Exception in instance %d: %s', $instance->getId(), $e->getMessage())
                );
            }
        }
    }

    // ------------------------------------------------------------------
    //  Instance processing
    // ------------------------------------------------------------------

    private function processInstance(PluginInstance $instance, $ticket) {
        $config = $instance->getConfig();
        if (!$config)
            return;

        if (!$config->get('webhook-enabled'))
            return;

        $url = trim($config->get('webhook-url'));
        if (!$url)
            return;

        // --- Department filter ---
        $departments = $config->get('departments');
        if (!is_array($departments))
            $departments = $departments ? array($departments) : array();

        if (!empty($departments)) {
            $deptId = (string) $ticket->getDeptId();
            if (!isset($departments[$deptId]) && !in_array($deptId, $departments))
                return;
        }

        // --- Build & send ---
        $payload = $this->buildPayload($ticket);
        $this->sendWebhook($url, $payload, $config);
    }

    // ------------------------------------------------------------------
    //  Payload
    // ------------------------------------------------------------------

    private function buildPayload($ticket) {
        $owner = $ticket->getOwner();
        $dept  = $ticket->getDept();

        // In osTicket 1.18.x several getters return strings rather than
        // ORM objects, so every return value is accessed defensively.

        $status   = $ticket->getStatus();
        $priority = $ticket->getPriority();
        $sla      = $ticket->getSLA();
        $topic    = $ticket->getHelpTopic();

        return array(
            'event'       => 'ticket.created',
            'ticket'      => array(
                'id'       => $ticket->getId(),
                'number'   => $ticket->getNumber(),
                'subject'  => $ticket->getSubject(),
                'status'   => self::safeGetProperty($status, 'getName'),
                'priority' => self::safeGetProperty($priority, 'getDesc')
                           ?: self::safeGetProperty($priority, 'getName')
                           ?: self::safeString($priority),
                'sla'      => self::safeGetProperty($sla, 'getName'),
                'source'   => $ticket->getSource(),
                'due_date' => $ticket->getEstDueDate(),
                'created'  => $ticket->getCreateDate(),
            ),
            'department'  => array(
                'id'   => $dept ? $dept->getId()   : null,
                'name' => $dept ? self::safeString($dept->getName()) : $ticket->getDeptName(),
            ),
            'requester'   => array(
                'name'  => $owner
                    ? self::safeString($owner->getName())
                    : self::safeString($ticket->getName()),
                'email' => $owner
                    ? $owner->getEmail()
                    : $ticket->getEmail(),
            ),
            'help_topic'  => is_object($topic)
                ? self::safeGetProperty($topic, 'getName')
                : self::safeString($topic),
            'assigned_to' => $this->getAssigneeInfo($ticket),
        );
    }

    private function getAssigneeInfo($ticket) {
        $info = array();

        if ($ticket->getStaffId() && ($staff = $ticket->getStaff())) {
            $info['staff'] = array(
                'id'    => $staff->getId(),
                'name'  => self::safeString($staff->getName()),
                'email' => $staff->getEmail(),
            );
        }

        if ($ticket->getTeamId() && ($team = $ticket->getTeam())) {
            $info['team'] = array(
                'id'   => $team->getId(),
                'name' => self::safeString($team->getName()),
            );
        }

        return $info ?: null;
    }

    // ------------------------------------------------------------------
    //  HTTP delivery
    // ------------------------------------------------------------------

    private function sendWebhook($url, array $payload, PluginConfig $config) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
            'User-Agent: osTicket-Webhook/1.0',
        );

        $customHeader = trim($config->get('webhook-custom-header') ?: '');
        if ($customHeader)
            $headers[] = $customHeader;

        // --- cURL setup ---
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // --- Basic Auth ---
        $username = trim($config->get('auth-username') ?: '');
        $password = $config->get('auth-password') ?: '';
        if ($username) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }

        // --- SSL verification ---
        if (!$config->get('verify-ssl')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        // --- DNS resolve override ---
        // Useful when the webhook hostname resolves to THIS server (reverse
        // proxy) causing SNI/SSL conflicts. Set to the real backend IP so
        // cURL connects directly, bypassing the local proxy.
        $resolveHost = trim($config->get('resolve-host') ?: '');
        if ($resolveHost) {
            $parsed = parse_url($url);
            $host   = $parsed['host'] ?? '';
            $scheme = $parsed['scheme'] ?? 'https';
            $port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
            if ($host) {
                curl_setopt($ch, CURLOPT_RESOLVE, array(
                    sprintf('%s:%d:%s', $host, $port, $resolveHost),
                ));
            }
        }

        // --- Debug logging ---
        $debug = (bool) $config->get('debug-log');
        $verbose = null;
        if ($debug) {
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }

        // --- Execute ---
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        $errno    = curl_errno($ch);

        $verboseLog = '';
        if ($verbose) {
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);
        }

        curl_close($ch);

        // --- Log result ---
        if ($error || $httpCode === 0 || $httpCode >= 400) {
            $msg = sprintf(
                "FAILED webhook to %s – HTTP %d – cURL(%d): %s",
                $url, $httpCode, $errno, $error ?: substr($response ?: '', 0, 300)
            );
            if ($verboseLog)
                $msg .= "\n  Verbose: " . $verboseLog;

            self::log($msg);

            // osTicket system log
            global $ost;
            if ($ost)
                $ost->logWarning('Ticket Webhook', $msg, false);

        } elseif ($debug) {
            self::log(sprintf(
                'OK webhook to %s – HTTP %d – %s',
                $url, $httpCode, substr($response ?: '', 0, 200)
            ));
        }
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /** Call $method on $obj only if it is a real object with that method. */
    private static function safeGetProperty($obj, $method) {
        if (is_object($obj) && method_exists($obj, $method))
            return $obj->$method();
        return null;
    }

    /** Convert any value to a plain string safely. */
    private static function safeString($val) {
        if ($val === null)
            return null;
        if (is_object($val)) {
            if (method_exists($val, 'getOriginal'))
                return $val->getOriginal();
            if (method_exists($val, '__toString'))
                return (string) $val;
            return get_class($val);
        }
        return (string) $val;
    }

    /** Append to the plugin log file (only when debug is likely on). */
    private static function log($message) {
        $logFile = dirname(__DIR__) . '/webhook.log';
        $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}


// ======================================================================
//  Configuration form
// ======================================================================

class TicketWebhookConfig extends PluginConfig {

    function getOptions() {
        return array(
            // --- Webhook ---
            'webhook-section' => new SectionBreakField(array(
                'label' => 'Webhook Settings',
                'hint'  => 'Configure the HTTP endpoint that receives ticket creation notifications.',
            )),
            'webhook-enabled' => new BooleanField(array(
                'label'   => 'Enable Webhook',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Activate this webhook instance',
                ),
            )),
            'webhook-url' => new TextboxField(array(
                'label'    => 'Webhook URL',
                'hint'     => 'Full URL that will receive the POST request (e.g. https://example.com/api/webhook).',
                'required' => true,
                'configuration' => array('size' => 80, 'length' => 512),
            )),

            // --- Auth ---
            'auth-section' => new SectionBreakField(array(
                'label' => 'Authentication (Basic Auth)',
                'hint'  => 'Optional HTTP Basic Authentication credentials. Leave blank to send unauthenticated requests.',
            )),
            'auth-username' => new TextboxField(array(
                'label' => 'Username',
                'configuration' => array('size' => 40, 'length' => 128),
            )),
            'auth-password' => new PasswordField(array(
                'label'  => 'Password',
                'widget' => 'PasswordWidget',
                'configuration' => array('size' => 40, 'length' => 128),
            )),

            // --- Departments ---
            'dept-section' => new SectionBreakField(array(
                'label' => 'Department Filter',
                'hint'  => 'Choose which departments trigger this webhook. If none are selected, ALL departments will trigger it.',
            )),
            'departments' => new ChoiceField(array(
                'label'    => 'Departments',
                'hint'     => 'Select one or more departments. Leave empty for all departments.',
                'required' => false,
                'choices'  => Dept::getDepartments(),
                'configuration' => array(
                    'multiselect' => true,
                    'prompt'      => 'All Departments',
                ),
            )),

            // --- Advanced ---
            'advanced-section' => new SectionBreakField(array(
                'label' => 'Advanced Settings',
            )),
            'verify-ssl' => new BooleanField(array(
                'label'   => 'Verify SSL Certificate',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Verify the SSL certificate of the webhook endpoint. Disable only for self-signed certificates.',
                ),
            )),
            'resolve-host' => new TextboxField(array(
                'label' => 'Resolve Host Override (IP)',
                'hint'  => 'If the webhook domain is reverse-proxied by THIS server, enter the real backend IP (e.g. 10.0.1.50). Leave empty for normal DNS.',
                'configuration' => array('size' => 40, 'length' => 64),
            )),
            'webhook-custom-header' => new TextboxField(array(
                'label' => 'Custom Header',
                'hint'  => 'Optional additional HTTP header (e.g. X-Api-Key: abc123).',
                'configuration' => array('size' => 80, 'length' => 256),
            )),
            'debug-log' => new BooleanField(array(
                'label'   => 'Enable Debug Logging',
                'default' => false,
                'configuration' => array(
                    'desc' => 'Write detailed request/response logs to webhook.log in the plugin directory.',
                ),
            )),
        );
    }

    function getFormOptions() {
        return array(
            'title'  => 'Ticket Webhook Configuration',
            'notice' => 'Configure the webhook endpoint, authentication, and department filters for this instance.',
        );
    }

    function pre_save(&$config, &$errors) {
        $url = trim($config['webhook-url'] ?? '');

        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['err'] = 'The Webhook URL does not appear to be a valid URL.';
            return false;
        }

        if ($url && stripos($url, 'http') !== 0) {
            $errors['err'] = 'The Webhook URL must start with http:// or https://.';
            return false;
        }

        return true;
    }
}
