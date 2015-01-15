<?php

/**
 * CodeIgniter limiter
 *
 * @license     MIT License
 * @package     Limiter
 * @author      Mechazawa
 * @version     1.0.0
 */


class Limiter {

    /** @type CI_Controller */
    protected $CI;
    protected $table = 'rate_limit';
    protected $base_limit = 0; // infinite
    protected $header_show = TRUE;
    protected $checksum_algorithm = 'md4';
    protected $header_prefix  = 'X-RateLimit-';
    protected $flush_on_abort = FALSE;
    protected $whitelist = array('127.0.0.1');

    protected $user_data = array();
    protected $user_hash = FALSE;

    private $_truncated = FALSE;

    private $_sql_truncate   = 'DELETE FROM `RATE_TABLE` WHERE `start` < (NOW() - INTERVAL 1 HOUR)';
    private $_sql_info       = 'SELECT `count`, `start`, (`start` + INTERVAL (1 - TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW())) HOUR) \'reset_epoch\' FROM `RATE_TABLE` WHERE `client` = ? AND `target` = ?';
    private $_sql_update     = 'INSERT INTO `RATE_TABLE` (`client`, `target`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `count` = `count` + 1';
    private $_config_fields  = array(
        'table', 'base_limit', 'checksum_algorithm',
        'header', 'header_prefix', 'flush_on_abort'
    );

    public function __construct($config = array()) {
        $this->CI = &get_instance();

        if(!is_array($config)) {
            $config = array();
        }

        foreach($this->_config_fields as $field) {
            if(array_key_exists($field, $config)) {
                $this->{$field} = $config[$field];
            }
        }

        $sql = array('truncate', 'info', 'update');
        foreach($sql as $s) {
            $this->{"_sql_$s"} = str_replace('RATE_TABLE', $this->table, $this->{"_sql_$s"});
        }

        $this->add_user_data($this->CI->input->ip_address());

        log_message('debug', 'Limiter Class Initialized');
    }

    /**
     * Rate limits the amount of requests that can be sent by a client.
     *
     * @param string $target
     * @param int $req_per_hour Overrides base_limit setting if set
     * @param bool $flush_on_abort Overrides flush_on_abort setting if set
     * @param bool $show_header Overrides header_show setting if set
     * @return bool Should request be aborted
     */
    public function limit($target = '_global', $req_per_hour = null, $flush_on_abort = null, $show_header = null) {
        $req_per_hour   = $req_per_hour ?: $this->base_limit;
        $flush_on_abort = $flush_on_abort ?: $this->flush_on_abort;
        $show_header    = $show_header ?: $this->header_show;

        $truncated = $this->_truncate();
        if(!$truncated) {
            log_message('DEBUG', 'WARN: Could not truncate rate limit table');
        }

        $ip_address = $this->CI->input->ip_address();
        if(in_array($ip_address, $this->whitelist)) {
            $req_per_hour = 0;
        }

        $abort = FALSE;
        if($req_per_hour > 0) {
            $data = array('client' => $this->get_hash(), 'target' => $target);

            $req_add = 0;
            $info    = $this->CI->db->query($this->_sql_info, $data)->row();

            if(!isset($info->count) || $req_per_hour - $info->count > 0) {
                $this->CI->db->query($this->_sql_update, $data);
                $req_add = 1;
            } else {
                $abort = TRUE;
            }

            if(!isset($info->count)) {
                $info              = new stdClass();
                $info->count       = 0;
                $info->reset_epoch = gmdate('d M Y H:i:s', time() + (60 * 60));
                $info->start       = date('d M Y H:i:s');
            }

            if($show_header === TRUE) {
                $headers = array(
                    'Limit' => $req_per_hour,
                    'Remaining' => $req_per_hour - $info->count - $req_add,
                    'Reset' => strtotime($info->reset_epoch),
                );

                foreach(array_keys($headers) as $h) {
                    $this->CI->output->set_header("$this->header_prefix$h: $headers[$h]");
                }
            }

            if($abort) {
                $retry_seconds = strtotime($info->reset_epoch) - strtotime(gmdate('d M Y H:i:s'));
                $this->CI->output->set_header("Retry-After: $retry_seconds");
                $this->CI->output->set_status_header(503, 'Rate limit reached');

                if($flush_on_abort) {
                    $this->CI->output->_display();
                    exit;
                }
            }
        }

        return $abort;
    }

    /**
     * Forget the client ever visited $target.
     *
     * @param string $target
     */
    public function reset_rate($target = '_global') {
        $this->CI->db->delete($this->table, array(
            'client' => $this->get_hash(),
            'target' => $target
        ));
    }

    /**
     * Forgets all rate limits attached to the client.
     */
    public function forget_client() {
        $this->CI->db->delete($this->table, array('client' => $this->get_hash()));
    }

    /**
     * Used to obtain the client hash.
     *
     * Returns false if hash generation failed
     * @return string
     */
    public function get_hash() {
        if($this->user_hash === FALSE) {
            $this->user_hash = $this->_generate_hash();
        }
        return $this->user_hash;
    }

    /**
     * Adds entropy to the client hash. Make
     * sure that this is some sort of static
     * data such as a username/id.
     *
     * @param string $data
     */
    public function add_user_data($data) {
        array_push($this->user_data, (string)$data);

        if($this->user_hash !== FALSE) {
            log_message('DEBUG', 'WARN: Adding user data after hash was generated');

            $this->user_hash = $this->_generate_hash();
        }
    }

    private function _truncate() {
        if(!$this->_truncated) {
            $this->_truncated = $this->CI->db->query($this->_sql_truncate);
        }
        return $this->_truncated;
    }

    private function _generate_hash() {
        return hash($this->checksum_algorithm, join('%', $this->user_data));
    }
}
