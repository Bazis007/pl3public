<?php

//PLATFORM3VER:2017111300

class pl3 {

    private static $run = [];
    private static $stream = [];
    private static $streams_final = [];
    private static $timer_start;
    private static $timers;
    private static $trace;
    private static $gpc;
//action_status
    static $status_status = 0;
    static $status_action_code;
    static $status_debugdata = [];
    static $status_usrmsg = [];
    static $status_admmsg = []; 
    static $status_throwable = true;
//ext
    private static $trace_skip = 0;
    private static $error_ignore = [];

    static function start() {
        self::timer_init();

        if (self::core_get('debug') == TRUE) {
            ini_set("display_errors", "1");
            error_reporting(E_ALL ^ E_NOTICE);
        }


        if (self::core_get('round') == '') {
            self::core_set('round', 6);
        }

        if (self::core_get_run_code() == '') {
            self::core_set_run_code(substr(pl3tools::megahash_uniq(''), 0, 8), FALSE);
        }
    }

//Core settings
    static function core_hardcore($maxlifetime = 3600) {
        set_time_limit($maxlifetime);
    }

    static function core_get($param) {
        if (isset(self::$run[$param])) {
            return self::$run[$param];
        }

        return '';
    }

    static function core_set($param, $value) {
        self::$run[$param] = $value;
    }

    static function core_get_run_code() {
        return self::core_get('run_code');
    }

    static function core_set_run_code($run_code, $update_trace = TRUE) {
        self::core_set('run_code', $run_code);

        if ($update_trace) {
            if (count(self::$trace) > 0) {
                foreach (self::$trace as $t_key => $t_val) {
                    self::$trace[$t_key]['run_code'] = $run_code;
                }
            }
        }
    }

    static function core_set_foot_stream($stream_name) {
        self::$streams_final[] = $stream_name;
    }

// Trace and error

    static function trace($msg, $level, $data = [], $source = '', $params = '') {
//        Trace levels:
//0 - debug steps
//1 - info messages (ok,etc)
//2 - warning messages on errors, input errors, no permission et.c
//3 - admin errors (sql, breaks, php errors)
//4 - hack attemps

        if (self::$trace_skip > 0) {
            self::$trace_skip--;
            return true;
        }

        if (self::core_get('trace') == TRUE) {
            $timer_name = isset($params['timer_name']) ? $params['timer_name'] : '';
            $timer_value = $timer_name != '' ? self::timer_stop($timer_name) : '0';

//$call_source = self::trace_find_call_point();
            $call_source = debug_backtrace();


            if ($level >= self::core_get('trace_level')) {
                self::$trace[] = ['time' => self::timer_now(), 'run_code' => self::core_get_run_code(), 'level' => $level, 'msg' => $msg, 'data' => $data, 'timer' => $timer_value, 'trace_src' => $source, 'call_source' => $call_source];
            }
        }
    }

    static function trace_skip($count) {
        self::$trace_skip = $count;
    }

    static function trace_out_array($is_clear = FALSE) {
        if ($is_clear) {
            $out = self::$trace;
            self::$trace = [];
            return $out;
        }
        return self::$trace;
    }

    static function trace_fileonshutdown($path) {



        self::core_set('trace_fileonshutdown', $path);
        register_shutdown_function(array('pl3', "trace_fileonshutdown_handler"));
    } 

    static function trace_fileonshutdown_handler() {
        try {
            if (count(self::$trace) == 0) {

                return FALSE;
            }
            $fp = fopen(self::core_get("trace_fileonshutdown"), 'a');
            if (!$fp) {
                return FALSE;
            }

            foreach (self::$trace as $t) {
                $to_file = $t['run_code'] . " " .
                        // pl3tools::form_date($t['time']) . " " .
                        $t['time'] . " " .
                        $t['level'] . " " .
                        $t['msg'] . "\n " .
                        //json_encode(['trace_src' => $t['trace_src'], 'timer' => $t['timer'], 'call_source' => $t['call_source'], 'data' => $t['data']]
                        pl3tools::ae_extend(['trace_src' => $t['trace_src'], 'timer' => $t['timer'], 'data' => $t['data'], 'call_source' => $t['call_source']], [], 0, "\n");
                fwrite($fp, $to_file . "\n");
            }
            fclose($fp);
            return TRUE;
        } catch (Trowable $exc) {
            if (pl3::core_get('debug') == 1) {
                echo $exc->getTraceAsString();
            } else {
                echo "ERROR! Check logs.";
            }
        }
    }

    static function core_halt($message) {

        echo $message;
        exit();
    }

    static function error_ingore($code) {
        if (!in_array($code, self::$error_ignore)) {
            self::$error_ignore[] = $code;
        }
    }

    static function error_handler($errno, $errmsg, $errfile, $errline) {

        if (in_array($errno, self::$error_ignore)) {
            return;
        }

        $level = 2;
        if ($errno == E_NOTICE || errno == E_DEPRECATED) {
            $level = 0;
        }

        pl3::trace("PHP error: $errmsg", $level, ['file' => $errfile, 'line' => $errline], 'pl3_error_handler');
        if (pl3::core_get('throw_error')) {
            throw new Exception("Error: " . "$errmsg [Error code: $errno] at [ $errline ] in [ $errfile ]");
        }
    }

    static function exception_handler(Throwable $ex) {

        pl3::trace("Exception:" . $ex->getMessage(), 2, ['file' => $ex->getFile(), 'line' => $ex->getLine(), 'code' => $ex->getCode(), 'trace' => $ex->getTrace()], 'pl3_error_handler');

        if (pl3::core_get('throw_exception')) {
            throw new Exception("Exception: " . $ex->getMessage());
        }
    }

    static function error_handle($throw_error = FALSE) {
        set_error_handler(['pl3', 'error_handler']);
        pl3::core_set('throw_error', $throw_error);
    }

    static function exception_handle($throw_exception = FALSE) {
        set_exception_handler(['pl3', 'exception_handler']);
        pl3::core_set('throw_exception', $throw_exception);
    }

// Update functions
//pl3_upd_send($is_version) (base64>json->[md5(body),body,version])
    static function core_update_send($is_send = TRUE) {
        $verpoint = 'PLATFORM3VER:';

        $body = file_get_contents(__FILE__);
        $md5 = md5_file(__FILE__);

        $version = substr($body, strpos($body, $verpoint) + strlen($verpoint), 10);

        if ($is_send) {
            $bodyout = base64_encode($body);
            echo "$version\n$md5\n$bodyout";
            exit();
        } else {
            return ['version' => $version, 'md5' => $md5, 'body' => $body];
        }
    }

    static function core_update($uri, $force = FALSE) {
        $verpoint = 'PLATFORM3VER:';
        try {
            $received_data = file_get_contents($uri);
        } catch (Throwable $exc) {
            self::trace("pl3_update: Unable to get content", 2, ['uri' => $uri, 'err' => $exc->getMessage(), 'received_data' => $received_data], 'pl3_upd');
            return FALSE;
        }

        $received_data_arr = explode("\n", $received_data);
        if (!$received_data_arr) {
            self::trace("pl3_update: Unable to split", 2, ['uri' => $uri, 'received_data' => $received_data], 'pl3_upd');
            return FALSE;
        }

        $current_body = file_get_contents(__FILE__);
        $current_version = substr($current_body, strpos($current_body, $verpoint) + strlen($verpoint), 10);

        $remote_version = $received_data_arr[0];
        $remote_md5 = $received_data_arr[1];
        $remote_body = base64_decode($received_data_arr[2]);

        if (($remote_version == $current_version) && ($force != TRUE)) {
            self::trace("pl3_update: Version is actual;", 2, ['uri' => $uri, 'remote_version' => $remote_version, 'current_version' => $current_version], 'pl3_upd');
            return TRUE;
        }

        if (!$remote_body) {
            self::trace("pl3_update: Unable decode remote data (base64)", 2, ['uri' => $uri, 'received_data' => $received_data, 'remote_body' => $remote_body], 'pl3_upd');
            return FALSE;
        }

        $remote_md5_fact = md5($remote_body);
        if ($remote_md5 != $remote_md5_fact) {
            self::trace("pl3_update: Unable decode remote data (base64)", 2, ['uri' => $uri, 'received_data' => $received_data, 'remote_body' => $remote_body, 'remote_md5_fact' => $remote_md5_fact], 'pl3_upd');
            return FALSE;
        }

// Done. Updating..

        $put_result = file_put_contents(__FILE__, $remote_body);
        if (!$put_result) {
            self::trace("pl3_update: Unable to update file.", 2, ['file' => __FILE__], 'pl3_upd');
            return FALSE;
        }

        self::trace("pl3_update: Update OK", 1, [], 'pl3_upd');
        return TRUE;
    }

// timer

    private static function timer_init() {
        self::$timer_start = time() + microtime();
    }

    static function timer_stat($timer_name) {
        self::$timers[$timer_name] = self::timer_now();
    }

    static function timer_stop($timer_name = '', $raw = false) {

        $time = self::timer_now() - self::$timers[$timer_name];
        unset(self::$timers[$timer_name]);

        if ($raw == TRUE) {
            return $time;
        }
        return round($time, self::core_get('round'));
    }

    static function timer_now() {
        return (time() + microtime() - self::$timer_start);
    }

// Template

    static function o($msg, $stream = 'main') {
        self::$stream[$stream] .= $msg;
    }

    static function tmpl_set_maintmpl_path($path) {
        self::core_set('maintmpl_path', $path);
    }

    static function tmpl_parce_file($val_arr, $path) {
        if (!is_array($val_arr)) {
            pl3::status_warning("Internal error.", "Core freeparcer: Input array error", func_get_args());
            return FALSE;
        }

        if (!file_exists($path)) {
            pl3::status_warning("Internal error.", "Core freeparcer: File not found:" . $path, ['input' => func_get_args(), 'path' => $path]);
            return FALSE;
        }

        $content = file_get_contents($path);
        if (!$content) {
            pl3::status_warning("Internal error.", "Core freeparcer: Read error:" . $path, ['input' => func_get_args(), 'path' => $path]);
            return FALSE;
        }

        while (list( $maskey, $masval ) = each($val_arr)) {
            $content = str_replace('{' . $maskey . '}', $masval, $content);
        }
        return $content;
    }

    static function tmpl_final_out() {
        foreach (self::$streams_final as $value) {
            $toparce[$value] = self::$stream[$value];
        }
        echo self::tmpl_parce_file($toparce, self::core_get('maintmpl_path'));
    }

    static function tmpl_catch_stream($stream) {
        $out = self::$stream[$stream];
        self::$stream[$stream] = '';
        return $out;
    }

    static function foot_deb_trace($params = []) {

        if (isset($params['trace_data'])) {
            $trace_src = $params['trace_data'];
        } else {
            $trace_src = self::$trace;
        }

        if (count($trace_src) > 0) {
            if (!isset($params['mode'])) {
                $params['mode'] = '2row';
            }


            if ($params['mode'] == '2row') {
                $tb = new table;
                $tb->param('border=1 width=90%');
                foreach ($trace_src as $value) {

                    $tb->td(round($value['time'], self::core_get('round')));
                    $tb->td($value['trace_src']);
                    $tb->td($value['msg']);
                    $tb->td(str_replace(pathinfo(__FILE__)['dirname'], '', $value['file']));
                    $tb->td($value['line']);
                    $tb->td($value['timer']);
                    $tb->tr();
                    $tb->td(self::ae($value['data']), 'colspan=6');
                    $tb->tr();
                }
                return $tb->out();
            }

            if ($params['mode'] == '2col') {
                $tb = new table;
                $tb->param('border=1 width=90%');

                foreach ($trace_src as $value) {
                    $col1 = '';
                    $col1 .= "<b>Msg</b>: " . "<b>" . $value['msg'] . "</b>" . pl3former::br(2);
                    $col1 .= "<b>Time</b>: " . round($value['time'], self::core_get('round')) . "<b> Run code</b>: " . $value['run_code'] . pl3former::br();
                    $col1 .= "<b>File</b>: " . str_replace(pathinfo(__FILE__)['dirname'] . '/', '', $value['file']) . "[" . $value['line'] . '] ' . "<b>Source</b>: " . $value['trace_src'] . pl3former::br();
                    if ($value['timer'] != 0) {
                        $col1 .= "<b> Timer</b>: " . $value['timer'] . " <B>Level</b>:" . $value['level'] . pl3former::br();
                    }
                    $tb->td($col1);
                    $tb->td(pl3tools::ae($value['data']));
                    $tb->tr();
                }
            }
            return $tb->out();
        } else {
            return '';
        }
    }

    static function foot_deb_mem() {
//memory_get_usage()
        return "Memory: " . pl3tools::form_memory(memory_get_usage(FALSE)) . " Peak:" . pl3tools::form_memory(memory_get_peak_usage(FALSE)) . pl3former::br();
    }

//env

    static function localize() {
        self::$gpc = array_merge($_GET, $_POST, $_COOKIE);
    }

    static function gpc($var_name, $new_value = '') {
        if ($new_value != '') {
            self::$gpc[$var_name] = $new_value;
            return self::$gpc[$var_name];
        }
        return self::$gpc[$var_name];
    }

    static function gpc_return() {
        return self::$gpc;
    }

    static function halt($pub_msg = "") {
        pl3::trace($pub_msg, 3);
        echo "<html><head><title>System halted</title></head><body><table align='center' bgcolor='#FFAAAA'><tr><td>$pub_msg</td></tr></table></body></html>";
        exit;
    }

// action_status
    static function status_reset($action_code = 'noname') {
        self::$status_action_code = $action_code;
        self::$status_admmsg = [];
        self::$status_usrmsg = [];
        self::$status_debugdata = [];
        self::$status_status = 0;
    }

    static function status_check() {
        if (self::$status_status == 1) {
            return TRUE;
        }
        return FALSE;
    }

    static function status_warning($user_message = '', $admin_message = '', $data = []) {
        if ($user_message != '') {
            self::status_messages_put_usr($user_message);
        }
        if ($admin_message != '') {
            self::status_messages_put_adm($admin_message);
        }
        if (count($data) > 0) {
            self::$status_debugdata[] = $data;
        }

        self::trace("Status info: User: $user_message Admin: $admin_message ", 0, $data, 'status');
    }

    static function status_done($user_message = '', $admin_message = '', $data = []) {
        self::$status_status = 1;

        if ($user_message != '') {
            self::status_messages_put_usr($user_message);
        }
        if ($admin_message != '') {
            self::status_messages_put_adm($admin_message);
        }
        if (count($data) > 0) {
            self::$status_debugdata[] = $data;
        }

        self::trace("Status done: User: $user_message Admin: $admin_message ", 1, $data, 'status');
    }

    static function status_fail($user_message = '', $admin_message = '', $data = [], $throw = true) {
        self::$status_status = 1;

        if ($user_message != '') {
            self::status_messages_put_usr($user_message);
        }
        if ($admin_message != '') {
            self::status_messages_put_adm($admin_message);
        }
        if (count($data) > 0) {
            self::$status_debugdata[] = $data;
        }
        self::$status_status = 2;
        self::trace("Status fail: User: $user_message Admin: $admin_message ", 2, $data, 'status');

        if ($throw) {
            throw new Exception($user_message);
        }
    }

    static function status_catch(Throwable $ex, $fail = true, $throw = true) {
        if ($fail) {
            self::status_fail($ex->getMessage(), "", $ex->getTrace(), $throw);
        }
        self::status_warning($ex->getMessage(), "", $ex->getTrace(), $throw);
    }

    private static function status_messages_put_adm($msg) {
        if (!in_array($msg, self::$status_admmsg)) {
            self::$status_admmsg[] = $msg;
        }
    }

    private static function status_messages_put_usr($msg) {
        if (!in_array($msg, self::$status_usrmsg)) {
            self::$status_usrmsg[] = $msg;
        }
    }

    static function status_messages($implode = "\n") {
        return implode($implode, self::$status_usrmsg);
    }

    static function status_messages_array() {
        return self::$status_usrmsg;
    }

    static function status_messages_adm($implode = "\n") {
        return implode($implode, self::$status_admmsg);
    }

    static function status_messages_array_adm() {
        return self::$status_admmsg;
    }

}

class table {

    var $table;   //tables array
    var $table_pos_row; // cursor pos row
    var $table_pos_col; // cursor pos col

    function __construct() {

        $this->table = array();
        $this->table_pos_row = 0;
        $this->table_pos_col = 0;
    }

    function td($val = '', $param = '') {
        $this->table['value'][$this->table_pos_row][$this->table_pos_col] = $val;

        if ($param != '') {
            $this->table['param'][$this->table_pos_row][$this->table_pos_col] = $param;
        }

        $this->table_pos_col ++;
    }

    function tr($val = '', $param = '') {


        if (is_array($val)) {
            $this->table['value'][$this->table_pos_row] = $val;
            if ($param != '') {
                $this->table['param'][$this->table_pos_row]['param'] = $param;
            }
        } elseif (is_string($val)) {
            if (strlen($val) > 0) {
                $this->td($val, $param);
            }
        }
        $this->table_pos_col = 0;
        $this->table_pos_row ++;
    }

    function param($val, $r = '', $c = '') {


        if ($r !== '' && $c == '') {
            $this->table['param'][$r]['param'] .= ' ' . $val;
        }
        if ($r !== '' && $c !== '') {
            $this->table['param'][$r][$c] .= ' ' . $val;
        }
        if ($r == '' && $c == '') {
            $this->table['param']['param'] .= ' ' . $val;
        }
    }

    function out() {
        /*
         *
         *  $tb['param']
         *  $tb['value']
         *
         * <table [param][param]>
         *  <tr [param][y][param]>
         *      <td [param][y][x]> .[value][y][x]. </td>
         */

        $out = '';
        $out .= "<table $table_class " . $this->table['param']['param'] . " >";
        for ($j = 0; $j < count($this->table['value']); $j++) {
            $out .= "<tr $table_tr " . $this->table['param'][$j]['param'] . " >";
            for ($i = 0; $i < count($this->table['value'][$j]); $i++) {
                $out .= "<td $table_td" . $this->table['param'][$j][$i] . " >\r\n";
                $out .= $this->table['value'][$j][$i];
                $out .= "</td>";
            }
            $out .= "</tr>\r\n";
        }
        $out .= "</table>";
        return $out;
    }

    function table_convert($db_table, $feeld_arr, $ext = []) {

        if (!is_array($ext)) {
            $ext = [];
        }
        $nr = count($db_table);

//TITLE!

        $x = 0;
        foreach ($feeld_arr as $key => $val) {
            $this->table['value'][0][$x] = $feeld_arr[$key]['title'];
            $x++;
        }

//other table
        if (count($db_table) > 0) {
            for ($i = 1; $i <= $nr; $i++) {
                $x = 0;
                foreach ($feeld_arr as $key => $val) {
                    if ($feeld_arr[$key]['type'] == 'db') {
                        $this->table['value'][$i][$x] = $db_table[$i - 1][$feeld_arr[$key]['name']];
//TODO: [F] Add sort urls
                    }

                    if ($feeld_arr[$key]['type'] == 'url') {

                        $acturl = $feeld_arr[$key]['url'];
                        $acturl = "<a href=\"$acturl\">" . $feeld_arr[$key]['msg'] . '</a>';
//up to 6 keys


                        if ($feeld_arr[$key]['key0'] != '') {
                            $acturl = str_replace('{0}', $db_table[$i - 1][$feeld_arr[$key]['key0']], $acturl);
                        }

                        if ($feeld_arr[$key]['key1'] != '') {
                            $acturl = str_replace('{1}', $db_table[$i - 1][$feeld_arr[$key]['key1']], $acturl);
                        }

                        if ($feeld_arr[$key]['key2'] != '') {
                            $acturl = str_replace('{2}', $db_table[$i - 1][$feeld_arr[$key]['key2']], $acturl);
                        }

                        if ($feeld_arr[$key]['key3'] != '') {
                            $acturl = str_replace('{3}', $db_table[$i - 1][$feeld_arr[$key]['key3']], $acturl);
                        }

                        if ($feeld_arr[$key]['key4'] != '') {
                            $acturl = str_replace('{4}', $db_table[$i - 1][$feeld_arr[$key]['key4']], $acturl);
                        }

                        if ($feeld_arr[$key]['key5'] != '') {
                            $acturl = str_replace('{5}', $db_table[$i - 1][$feeld_arr[$key]['key5']], $acturl);
                        }


                        $this->table['value'][$i][$x] = $acturl;
                    }

                    $x++;
                }
            }
        }

        /* OLD manual
         * 
         * 
         * EXAMPLES::: NOT DELETE!!!!
         * $table_db - table,selected, from base
         * $feeld_arr[0]['title']  = 'PARCED_TITLE_CONST';
         * $feeld_arr[$i]['name']  = 'id';
         * $feeld_arr[$i]['type']  = 'db'; db/extra
         * $feeld_arr[$i]['issort']  = 0/1
         * $feeld_arr[$i]['msg']  = 'CONST_PARCED_NAME'; db/extra
         * $feeld_arr[$i]['url']  = 'user_id'; if extra
         * $feeld_arr[$i]['ctrl_feeld']  = 'id'; if extra
         * $feeld_arr[$i]['act_url']  = '&act = [] '; if extra
         *
         * $ext['base_url'] = '{home}&dfsdf=222'
         * $ext['use_split'] = '0/1';
         * $ext['split_...'] = ....
         *
         *  $tb['param']
         *  $tb['value']
         *
         * <table [param][param]>
         *  <tr [param][y][param]>
         *      <td [param][y][x]> .[value][y][x]. </td>
         *
         * return $out['table']
         * return $out['nav']
         *
         *  Example:
         * $feelds[] = array('title'=>msg('SYS_ADM_USERS_TB_ISEXT'),'name'=>'is_extended','type'=>'db');
         *  $feelds[] = array('title'=>msg('SYS_ADM_USERS_TB_CONTROL'),'type'=>'extra','msg'=>msg('SYS_ADM_USERS_TB_CONTROL'),'act_url'=>'&section=ctrl','url'=>'user_id','ctrl_feeld'=>'id');
          $ext['base_url'] = '{home}?engine=admin&part=users&act=delete';
         *
         * upd version 2: 
         */
    }

}

class pl3_amqp_tool {

//depricated!

    var $amqp_host;
    var $amqp_login;
    var $amqp_psw;
    var $amqp_vhost;
    var $amqp_port = 5672;
    var $ready = false;
// for receive
    var $connection;
    var $queue;
    var $message;
    var $exchange;

    function __construct($run) {

        if ($run['amqp_host'] != '') {
            $this->amqp_host = $run['amqp_host'];
        }
        if (isset($run['amqp_login'])) {
            $this->amqp_login = $run['amqp_login'];
        }
        if (isset($run['amqp_psw'])) {
            $this->amqp_psw = $run['amqp_psw'];
        }
        if (isset($run['amqp_vhost'])) {
            $this->amqp_vhost = $run['amqp_vhost'];
        }
        if (isset($run['amqp_port'])) {
            $this->amqp_port = $run['amqp_port'];
        }
    }

    function start_receiver($queue_name) {

        $this->connection = new AMQPConnection();
        $this->connection->setLogin($this->amqp_login);
        $this->connection->setPassword($this->amqp_psw);
        $this->connection->setPort($this->amqp_port);

        try {
            $this->connection->connect();
        } catch (AMQPConnectionException $exc) {
            pl3::status_fail("Connection error.", 'AMQP connect error: ' . $exc->getMessage(), ['ex' => $exc->getTraceAsString()]);
            return FALSE;
        }

        try {
            $this->channel = new AMQPChannel($this->connection);
            $this->queue = new AMQPQueue($this->channel);
            $this->queue->setName($queue_name);
            $this->queue->setFlags(AMQP_NOPARAM);
            $this->queue->declareQueue();

            return TRUE;
        } catch (Throwable $exc) {
            self::err();
            pl3::status_fail("AMQP start receiver error", "AMQP start receiver error:" . $exc->getMessage(), ['ex' => $exc->getTraceAsString()]);
        }
    }

    function get() {
        try {
            $this->message = $this->queue->get(AMQP_NOPARAM);
            if ($this->message == FALSE) {
// err("AMQP receive error! Common. ");
// no messages
                return FALSE;
            }

            try {

                return $this->message->getBody();
            } catch (Throwable $exc) {
                pl3::status_fail("AMQP get body error", "Message:" . $exc->getMessage(), ['ex' => $exc->getTraceAsString()]);
            }
        } catch (AMQPException $exc) {

            pl3::status_fail("AMQP receive error", "Message:" . $exc->getMessage(), ['ex' => $exc->getTraceAsString()]);
        }
        return FALSE;
    }

    function ack() {
        try {
            $this->queue->ack($this->message->getDeliveryTag());
        } catch (Throwable $exc) {
            echo $exc->getMessage();
        }
    }

    function nack() {
        $this->queue->nack($this->message->getDeliveryTag());
    }

    function nack_requeue() {
        $this->queue->nack($this->message->getDeliveryTag(), AMQP_REQUEUE);
    }

    function close() {

        $this->connection->disconnect();
    }

}

class pl3runner {

// Runner

    /*
     * function exec($run_item)
     * function set_action('run_item','function_name','params')
     * function set_check('run_item','function_name','params')
     * function set_pre('run_item','function_name','params')
     * function set_post('run_item','function_name','params')
     * function set_next('run_item')
     * 
     * 
     * 
     * run_arr['run_item']['action'][function_name]
     * run_arr['run_item']['action'][params]
     * run_arr['run_item']['check'][][function_name]
     * run_arr['run_item']['check'][][params]
     * run_arr['run_item']['pre'][][function_name]
     * run_arr['run_item']['pre'][][params]
     * run_arr['run_item']['post'][][function_name]
     * run_arr['run_item']['post'][][params]
     * run_arr['run_item']['next'][function_name]
     * 
     * action - main action
     * check - return true or false(i.e. acces, input values, etc)
     * pre - function, which run before (i.e. menu draw)
     * post - functions, which run after main action
     * 
     * examples:
     * bool check_function($param1,$param2) => ::runner_set_check('check_function',[$param1,$param2...])
     * bool action_function($param1,$param2) => ::runner_set_action('check_action',[$param1,$param2...])
     * 
     * 
     * 
     * * */

    private static $runner;
    private static $runner_status_code;
    private static $runner_status;
    private static $runner_handlers;

    static function set_action($run_item, $function_name, $params = array()) {
        if (!is_array($params)) {
            $params = array($params);
        }
        self::$runner[$run_item]['action']['function_name'] = $function_name;
        self::$runner[$run_item]['action']['params'] = $params;
    }

    static function set_check($run_item, $function_name, $params = array()) {
        if (!is_array($params)) {
            $params = array($params);
        }

        self::$runner[$run_item]['check'][] = array('function_name' => $function_name, 'params' => $params);
    }

    static function set_pre($run_item, $function_name, $params = array()) {
        if (!is_array($params)) {
            $params = array($params);
        }
        self::$runner[$run_item]['pre'][] = array('function_name' => $function_name, 'params' => $params);
    }

    static function set_post($run_item, $function_name, $params = array()) {
        if (!is_array($params)) {
            $params = array($params);
        }
        self::$runner[$run_item]['post'][] = array('function_name' => $function_name, 'params' => $params);
    }

    static function set_next($run_item, $next_item) {
        self::$runner[$run_item]['next'] = $next_item;
    }

    static function set_handler_function($function_name, $result, $handler_function, $is_final = FALSE) {
        self::$runner_handlers[$function_name][] = ['result' => $result, 'handler_function' => $handler_function, 'is_final' => $is_final];
    }

    static function get_status($return_code = FALSE) {
        if ($return_code) {
            return self::$runner_status_code;
        }
        return self::$runner_status;
    }

    private static function check_handler($function, $result) {
        if (isset(self::$runner_handlers[$function])) {
            foreach (self::$runner_handlers[$function] as $handlers) {
                if ($handlers['result'] == $result) {
                    try {
                        call_user_func($result['handler_function']);
                    } catch (Exception $ex) {
                        pl3::status_catch($ex);
                    }

                    if ($handlers['is_final']) {
                        return FALSE;
                    }
                }
            }
        }


        return true;
        // if(return false) ==> exit from runner_exec
    }

    static function exec($run_item) {
        try {
            if (isset(self::$runner[$run_item])) {

                // checks
                $check_status = TRUE;

                if (isset(self::$runner[$run_item]['check'])) {
                    foreach (self::$runner[$run_item]['check'] as $v_pre) {
                        $result = call_user_func_array($v_pre['function_name'], $v_pre['params']);

                        if (!self::check_handler($v_pre['function_name'], $result)) {
                            self::$runner_status_code = 'HANDLEREXIT';
                            self::$runner_status = FALSE;
                            return FALSE;
                        }

                        if ($result == FALSE) {
                            $check_status = FALSE;
                        }
                    }
                }

                if ($check_status == FALSE) {
                    self::$runner_status_code = 'CHECKERR';
                    self::$runner_status = FALSE;
                    return FALSE;
                }

                //pre
                if (isset(self::$runner[$run_item]['pre'])) {
                    foreach (self::$runner[$run_item]['pre'] as $v_pre) {

                        if (function_exists($v_pre['function_name'])) {
                            $pre_result = call_user_func_array($v_pre['function_name'], $v_pre['params']);

                            if (!self::check_handler($v_pre['function_name'], $pre_result)) {
                                self::$runner_status_code = 'HANDLEREXIT';
                                self::$runner_status = FALSE;
                                return FALSE;
                            }
                        } else {
                            self::$runner_status_code = 'PREFUNNOTFOUD';
                            self::$runner_status = FALSE;
                            return FALSE;
                        }
                    }
                }

                if (isset(self::$runner[$run_item]['action'])) {
                    if (function_exists(self::$runner[$run_item]['action']['function_name'])) {
                        $action_result = call_user_func_array(self::$runner[$run_item]['action']['function_name'], self::$runner[$run_item]['action']['params']);

                        if (!self::check_handler($v_pre['function_name'], $action_result)) {
                            self::$runner_status_code = 'HANDLEREXIT';
                            self::$runner_status = FALSE;
                            return FALSE;
                        }
                    } else {
                        self::$runner_status_code = 'ACTFUNNOTFOUD';
                    }
                } else {
                    self::$runner_status_code = 'ACTIONNOTDEFINDED';
                    self::$runner_status = FALSE;
                    return FALSE;
                }

                if (isset(self::$runner[$run_item]['post'])) {
                    foreach (self::$runner[$run_item]['post'] as $v_pre) {
                        if (function_exists($v_pre['function_name'])) {
                            $post_result = call_user_func_array($v_pre['function_name'], $v_pre['params']);
                            if (!self::check_handler($v_pre['function_name'], $post_result)) {
                                self::$runner_status_code = 'HANDLEREXIT';
                                self::$runner_status = FALSE;
                                return FALSE;
                            }
                        } else {
                            self::$runner_status_code = 'POSTFUNNOTFOUD';
                            self::$runner_status = FALSE;
                            return FALSE;
                        }
                    }
                }
            } else {
                self::$runner_status_code = 'NOACTION';
                self::$runner_status = FALSE;
                return FALSE;
            }

            if (isset(self::$runner[$run_item]['next'])) {
                return self::runner_exec(self::$runner[$run_item]['next']);
            }

            self::$runner_status_code = 'OK';
            self::$runner_status = TRUE;

            return TRUE;
        } catch (Throwable $t) {
            pl3::status_catch($t);
        }
    }

}

class pl3nav {

    private static $nav;

// Navigation
    /*
      v3:

      1. Через функцию кладутся элементы навигации
      - Так же указывается поток.
      2. Функция возвращает требуемое меню в виде массива (рисуется фнкцией пользователя) (на выходе массив)

     * [!] По умолчанию меню 'main', 
      nav_put_item('msg','url',menu_name='main',$params = array())
      nav_get_attay($menu_name='main');
      ['main']['items'][]['msg']=...
      ['main']['items'][]['url']=...
      ['main']['items'][]['params']=...(mixed)

     */

    static function set_menu($msg, $url, $menu_name = 'main', $params = array()) {
        self::$nav[$menu_name]['msg'] = $msg;
        self::$nav[$menu_name]['url'] = $url;
        self::$nav[$menu_name]['params'] = $params;
    }

    static function put_item($msg, $url, $menu_name = 'main', $params = array()) {
        self::$nav[$menu_name]['items'][] = array('msg' => $msg, 'url' => $url, 'params' => $params);
    }

    static function get_array($menu_name = '') {
        if ($menu_name == '') {
            return self::$nav;
        } else {
            return self::$nav[$menu_name];
        }
    }

}

class pl3lang {

//Lang
    private static $lang;

    static function lang_load_file($path) {
        if (!file_exists($path)) {
            pl3::status_warning("Can't load lang file.", "LangLoadFile: File not found", ['path' => $path]);
            return FALSE;
        }

        $content = file_get_contents($path);
        if (!$content) {

            pl3::status_warning("Read error", "Read error.", ['path' => $path]);
            return FALSE;
        }

        $arr_l1 = explode("\n", $content);
        foreach ($arr_l1 as $v1) {
            $v1 = explode("|", $v1);
            self::lang_const_set($v1[0], $v1[1]);
        }
    }

    static function msg($key) {
        return self::$lang[$key];
    }

    static function lang_const_set($key, $val) {
        self::$lang[$key] = $val;
    }

    static function lang_const_rm($key) {
        unset(self::$lang[$key]);
    }

}

class pl3list {

    private static $list;

// List

    static function list_load_file($list_name, $path) {
        if (!file_exists($path)) {
            pl3::status_warning("Error", "List file: File not found", ['path' => $path]);
            return FALSE;
        }

        $content = file_get_contents($path);
        if (!$content) {
            pl3::status_warning("Error", "List file: File read error", ['path' => $path]);
            return FALSE;
        }

        $arr_l1 = explode("\n", $content);
        foreach ($arr_l1 as $v1) {
            $v1 = explode("|", $v1);
            self::list_set_val($list_name, $v1[0], $v1[1]);
        }
    }

    static function list_load_array($list_name, $kv_array) {
        foreach ($kv_array as $key => $value) {
            self::list_set_val($list_name, $key, $value);
        }
    }

    static function list_set_val($list_name, $key, $value) {
        self::$list[$list_name][$key] = $value;
    }

    static function list_rm($list_name, $key = '') {
        if ($key == '') {
            unset(self::$list[$list_name]);
            return true;
        }
        unset(self::$list[$list_name][$key]);
    }

    static function list_get($list_name, $key = '') {
        if ($key == '') {
            return self::$list[$list_name];
        }
        return self::$list[$list_name][$key];
    }

}

class pl3former {

//FORMER

    static function create_form($action, $content, $hidden = '', $arr = array()) {
        if ($arr['method'] == '') {
            $method = 'POST';
        } else {
            $method = $arr['method'];
        }

        if ($arr['class'] != '') {
            $add .= ' class="' . $arr['class'] . '" ';
        }
        if ($arr['id'] != '') {
            $add .= ' id="' . $arr['id'] . '" ';
        }
        if ($arr['target'] != '') {
            $add .= ' target="' . $arr['target'] . '" ';
        }
        if ($arr['name'] != '') {
            $add .= ' name="' . $arr['name'] . '" ';
        }
        if ($arr['add'] != '') {
            $add .= $arr['add'];
        }


        return "
        <form action='$action' ENCTYPE='multipart/form-data' method='$method' $add >
                $hidden
                $content
            </form>";
    }

    static function fileupl($name, $arr = array()) {

        $maxsize = $arr['maxsize'];
        $size = $arr['size'];
        $class = $arr['class'];
        $id = $arr['id'];

        if ($maxsize != '') {
            $ms = '<input type="hidden" name="MAX_FILE_SIZE" value="' . $maxsize . '">' . "\r\n";
        }
        return "$ms<input type='file' size='$size' name='$name' class='$class' id='$id'>";
        /*
         * $_FILES["file"]["name"] - the name of the uploaded file
         * $_FILES["file"]["type"] - the type of the uploaded file
         * $_FILES["file"]["size"] - the size in bytes of the uploaded file
         * $_FILES["file"]["tmp_name"] - the name of the temporary copy of the file stored on the server
         * $_FILES["file"]["error"] - the error code resulting from the file upload
         * 
         */
    }

    static function text($name, $value = '', $arr = array()) {
        $maxlen = $arr['maxlen'];
        $size = $arr['size'];
        $class = $arr['class'];
        $id = $arr['id'];

        $add = '';
        if ($value != '') {
            $add .= "value='$value' ";
        }
        if ($maxlen != '') {
            $add .= "maxlen='$maxlen' ";
        }
        if ($size != '') {
            $add .= "size='$size' ";
        }
        if ($class != '') {
            $add .= "class='$class' ";
        }
        if ($id != '') {
            $add .= "id='$id' ";
        }

        return "<input type='text' name='$name' $add>";
    }

    static function textarea($name, $value, $arr = array()) {
        $rows = $arr['rows'];
        $cols = $arr['cols'];

        $class = $arr['class'];
        $id = $arr['id'];

        $add = '';
        if ($rows != '') {
            $add .= "rows='$rows' ";
        }
        if ($cols != '') {
            $add .= "cols='$cols' ";
        }
        if ($class != '') {
            $add .= "class='$class' ";
        }
        if ($id != '') {
            $add .= "id='$id' ";
        }

        return "<textarea name='$name' $add>$value</textarea>";
    }

    static function password($name, $value, $arr = array()) {

        $maxlen = $arr['maxlen'];
        $size = $arr['size'];
        $class = $arr['class'];
        $id = $arr['id'];


        $add = '';
        if ($size != '') {
            $add .= "size='$size' ";
        }
        if ($value != '') {
            $add .= "value='$value' ";
        }
        if ($maxlen != '') {
            $add .= "maxlen='$maxlen' ";
        }


        if ($class != '') {
            $add .= "class='$class' ";
        }
        if ($id != '') {
            $add .= "id='$id' ";
        }

        return "<input type='password' name='$name' $add>";
    }

    static function select($name, $arr_val, $sel_value = '', $arr = array()) {
        $use_lang = $arr['use_lang'];
        $void = $arr['void'];
        $size = $arr['size'];
        $class = $arr['class'];
        $id = $arr['id'];

        if ($use_lang == '') {
            $use_lang = 0;
        }


        if (!is_array($arr_val) && $arr['void'] == '') {
            self::err($name . ': list incorrect');
            return false;
        }
        $out = "<select name='$name' id='$id' class='$class' size='$size'>";

        if ($void != '') {
            $out .= "<option value='void'>$void</option>";
        }
        if (is_array($arr_val)) {
            foreach ($arr_val as $key => $val) {

                if ($key == $sel_value) {
                    $add = " selected ";
                }
                if ($use_lang == 1) {
                    $out .= "<option value='$key' $add >" . msg($val) . "</option>";
                } else {
                    $out .= "<option value='$key' $add >$val</option>";
                }
                $add = '';
            }
        }
        $out .= '</select>';
        return $out;
    }

    static function select_yn($name, $sel_value = '', $arr = array()) {
        $arr_val[0] = msg('SYS_FORMER_SELYN_NO');
        $arr_val[1] = msg('SYS_FORMER_SELYN_YES');
        return former_select($name, $arr_val, $sel_value, $arr);
    }

    static function select_num($name, $min, $max, $sel_value = '', $arr = array()) {
        $void = $arr['void'];
        $class = $arr['class'];
        $id = $arr['id'];


        $out = "<select name='$name' id='$id' class='$class' size='$size'>";

        if ($void != '') {
            $out .= "<option value=''>$void</option>";
        }

        for ($i = $min; $i <= $max; $i++) {
            if ($i == $sel_value) {
                $add = " selected ";
            }
            $out .= "<option value='$i' $add >$i</option>";
            $add = '';
        }
        $out .= '</select>';
        return $out;
    }

    static function hidden($name, $value, $arr = array()) {
        $add = '';
        if ($id != '') {
            $add .= "id='$id' ";
        }

        $id = $arr['id'];
        return "<input type='hidden' name='$name' value='$value' $add>\r\n";
    }

    static function chb($name, $chk, $arr = array()) {

        $class = $arr['class'];
        $id = $arr['id'];

        if ($chk) {
            $ch = ' checked ';
        }

        $add = '';
        if ($class != '') {
            $add .= "class='$class' ";
        }
        if ($id != '') {
            $add .= "id='$id' ";
        }

        return "<input type='checkbox' name='$name' $ch $add>";
    }

    static function submit($msg, $arr = array()) {
        $class = $arr['class'];
        $id = $arr['id'];

        $add = '';
        if ($class != '') {
            $add .= "class='$class' ";
        }
        if ($id != '') {
            $add .= "id='$id' ";
        }

        return "<button type='submit' $add>$msg</button>\r\n";
    }

    static function reset($msg, $arr = array()) {

        $class = $arr['class'];
        $id = $arr['id'];

        $add = '';

        if ($class != '') {
            $add .= "class='$class' ";
        }

        if ($id != '') {
            $add .= "id='$id' ";
        }

        return "<button type='submit' $add>$msg</button>";
    }

    static function url($url, $text, $arr = array()) {

        if (is_array($url)) {
            $url = url_transit($url, 1);
        }

        $add_pre = '';
        $add_post = '';
        $add = '';

        if ($arr['target'] != '') {
            $add .= " target='" . $arr['target'] . "' ";
        }

        if ($arr['id'] != '') {
            $add .= " id='" . $arr['id'] . "' ";
        }

        if ($arr['class'] != '') {
            $add .= " class='" . $arr['class'] . "' ";
        }



        if ($arr['noindex'] == 1) {
            $add_pre .= '<noindex>';
            $add_post .= '</noindex>';
            $add .= ' rel="nofollow" ';
        }

        $add .= $arr['add'];

        return "$add_pre<a href='$url' $target $add>$text</a>$add_post";
    }

    static function img($src, $arr = array()) {
        $class = $arr['class'];
        $id = $arr['id'];

        $add = '';

        if ($class != '') {
            $add .= "class='$class' ";
        }

        if ($id != '') {
            $add .= "id='$id' ";
        }

        $add .= ' ' . $arr['add'];
        return "<img src='$src' $add>\r\n";
    }

    static function br($num_br = 1) {
        $out = '';
        for ($i = 0; $i < $num_br; $i++) {
            $out .= "</BR>\r\n";
        }
        return $out;
    }

}

class pl3fsm {

//fsm
    private static $fsm_sets;

// fsm
// pl3_fsm {mh,store_mh,src_name,ext,uploaded,is_enabled)
    static function fsm_set_path($path_to_store_folder) {
        self::$fsm_sets['path'] = $path_to_store_folder;
    }

    static function fsm_set_url_path($url_path) {
        self::$fsm_sets['url'] = $url_path;
    }

    static function fsm_put($post_var_name, $force_ext = '', $connection_name = 'main') {
//pl3_fsm {mh,store_mh,src_name,ext,size,uploaded,is_enabled)
//Check input
        if ($_FILES[$post_var_name]['error'] != UPLOAD_ERR_OK) {
            pl3::trace('Post upload error', 2, ['post' => $_FILES[$post_var_name]], 'pl3_fsm');
            return FALSE;
        }

        $tmp_name = $_FILES[$post_var_name]["tmp_name"];
        $fname_src = $_FILES[$post_var_name]["name"];
        $file_size = $_FILES[$post_var_name]['size'];

        $src_filepath = pathinfo($fname_src);

        $to_db = [];
        $to_db['mh'] = self::megahash_uniq($fname_src);
        $to_db['store_mh'] = self::megahash_uniq($fname_src);
        $to_db['src_name'] = $fname_src;
        $to_db['size'] = $file_size;
        $to_db['uploaded'] = time();
        $to_db['is_enabled'] = 1;
        if ($force_ext == '') {
            $to_db['ext'] = $src_filepath['extension'];
        } else {
            $to_db['ext'] = $force_ext;
        }

        pl3db::insert('fsm', $to_db, [], $connection_name);

        $new_path = self::$fsm_sets['path'] . '/' . $to_db['store_mh'] . "." . $to_db['ext'];
        $moove_res = move_uploaded_file($tmp_name, $new_path);
        if (!$moove_res) {
            pl3::trace("Can't moove uploaded file", 2, ['post' => $_FILES[$post_var_name], 'moove_res' => $moove_res, 'tmp_name' => $tmp_name, 'new_path' => $new_path], 'pl3_fsm');
            return FALSE;
        }

        pl3::trace("Upload OK.", 2, ['post' => $_FILES[$post_var_name], 'new_path' => $new_path, 'to_db' => $to_db], 'pl3_fsm');
        return TRUE;
    }

    static function fsm_get_url($fsm_mh, $force = FALSE, $connection_name = 'main') {
//возвращает utl для загрузки с http или false
        $sel_status = pl3db::select('fsm', '', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$sel_status) {
            pl3::trace("fsm_get_url SQL error", 2, ['params' => func_get_args(), 'err' => pl3db::get_query_error($connection_name)], 'pl3_fsm');
            return FALSE;
        }

        $res = pl3db::fetch_result_row($connection_name);
        if (count($res)) {
            pl3::trace("fsm_get_url record not found.", 1, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        if ($res['is_enabled'] != 1 && $force != TRUE) {
            pl3::trace("fsm_get_url file disabled.", 0, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        $return = self::$fsm_sets['url'] . "/" . $res['store_mh'] . '.' . $res['ext'];
        pl3::trace("fsm_get_url ok.", 0, ['params' => func_get_args(), 'result' => $return], 'pl3_fsm');
        return $return;
    }

    static function fsm_get_content($fsm_mh, $force = FALSE, $connection_name = 'main') {
//возвращает utl для загрузки с http или false
        $sel_status = pl3db::select('fsm', '', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$sel_status) {
            pl3::trace("fsm_get_content SQL error", 2, ['params' => func_get_args(), 'err' => pl3db::get_query_error($connection_name)], 'pl3_fsm');
            return FALSE;
        }

        $res = pl3db::fetch_result_row($connection_name);
        if (count($res) == 0) {
            pl3::trace("fsm_get_content record not found.", 1, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        if ($res['is_enabled'] != 1 && $force != TRUE) {
            pl3::trace("fsm_get_content file disabled.", 0, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        $return = self::$fsm_sets['path'] . "/" . $res['store_mh'] . '.' . $res['ext'];

        try {
            $return_content = file_get_contents($return);
        } catch (Throwable $exc) {
            pl3::trace("fsm_get_content file read error", 2, ['params' => func_get_args(), 'result' => $return, 'msg' => $exc->getMessage()], 'pl3_fsm');
            return FALSE;
        }

        pl3::trace("fsm_get_content ok.", 0, ['params' => func_get_args(), 'result' => $return], 'pl3_fsm');
        return $return_content;
//Возвращает содержимое файла
    }

    static function fsm_get_meta($fsm_mh, $connection_name = 'main') {
//Возвращает метаданные
        $sel_status = pl3db::select('fsm', '', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$sel_status) {
            pl3::trace("fsm_get_meta SQL error", 2, ['params' => func_get_args(), 'err' => pl3db::get_query_error($connection_name)], 'pl3_fsm');
            return FALSE;
        }

        $res = pl3db::fetch_result_row($connection_name);
        if (count($res) == 0) {
            pl3::trace("fsm_get_meta  record not found.", 1, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        pl3::trace("fsm_get_meta ok.", 0, ['params' => func_get_args(), 'result' => $res], 'pl3_fsm');
        return $res;
    }

    static function fsm_rename($fsm_mh, $connection_name = 'main') {
//изменяет store_mh и переименовывает файл (в базе тоже меняет)


        $sel_status = pl3db::select('fsm', '', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$sel_status) {
            pl3::trace("fsm_rename SQL error", 2, ['params' => func_get_args(), 'err' => pl3db::get_query_error($connection_name)], 'pl3_fsm');
            return FALSE;
        }

        $res = pl3db::fetch_result_row($connection_name);
        if (count($res) == 0) {
            pl3::trace("fsm_rename record not found.", 1, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        $old_path = self::$fsm_sets['path'] . "/" . $res['store_mh'] . '.' . $res['ext'];
        $new_mh = self::megahash_uniq($old_path);
        $new_path = self::$fsm_sets['path'] . "/" . $new_mh . '.' . $res['ext'];

        try {
            $rename_result = rename($old_path, $new_path);
        } catch (Throwable $exc) {
            pl3::trace("fsm_rename rename exeption", 2, ['params' => func_get_args(), 'old' => $old_path, 'new' => $new_path, 'msg' => $exc->getMessage()], 'pl3_fsm');
            return FALSE;
        }

        if (!$rename_result) {
            pl3::trace("fsm_rename rename error", 2, ['params' => func_get_args(), 'old' => $old_path, 'new' => $new_path], 'pl3_fsm');
            return FALSE;
        }

        $upd_res = pl3db::update('fsm', ['store_mh' => $new_mh], "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$upd_res) {
            pl3::trace("fsm_rename db_error", 2, ['params' => func_get_args(), 'old' => $old_path, 'new' => $new_path, 'new_fsm_mh' => $new_mh], 'pl3_fsm');
            return FALSE;
        }

        pl3::trace("fsm_rename ok", 0, ['params' => func_get_args(), 'old' => $old_path, 'new' => $new_path, 'new_fsm_mh' => $new_mh], 'pl3_fsm');
        return TRUE;
    }

    static function fsm_del($fsm_mh, $connection_name = 'main') {
// удаляет файл и запись в базе
        $sel_status = pl3db::select('fsm', '', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$sel_status) {
            pl3::trace("fsm_del SQL error", 2, ['params' => func_get_args(), 'err' => pl3db::get_query_error($connection_name)], 'pl3_fsm');
            return FALSE;
        }

        $res = pl3db::fetch_result_row($connection_name);
        if (count($res) == 0) {
            pl3::trace("fsm_del record not found.", 1, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        try {
            $path = self::$fsm_sets['path'] . "/" . $res['store_mh'] . '.' . $res['ext'];
            unlink($path);
            pl3::trace("fsm_del file deleting ok", 0, ['params' => func_get_args()], 'pl3_fsm');
        } catch (Throwable $exc) {
            pl3::trace("fsm_del file read error", 2, ['params' => func_get_args(), 'msg' => $exc->getMessage()], 'pl3_fsm');
        }

        $del_status = pl3db::delete('fsm', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$del_status) {
            pl3::trace("fsm_del db record delete error", 2, ['params' => func_get_args(), 'db_msg' => pl3db::get_query_error()], 'pl3_fsm');
            return FALSE;
        }

        pl3::trace("fsm_del ok", 0, ['params' => func_get_args()], 'pl3_fsm');
        return TRUE;
    }

    static function fsm_ch_state($fsm_mh, $active_state, $rename = FALSE, $connection_name = 'main') {
// Изменяет активен или неактивен файл (и переименовывает)

        $sel_status = pl3db::select('fsm', '', "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$sel_status) {
            pl3::trace("fsm_ch_state SQL error", 2, ['params' => func_get_args(), 'err' => pl3db::get_query_error($connection_name)], 'pl3_fsm');
            return FALSE;
        }

        $res = pl3db::fetch_result_row($connection_name);
        if (count($res) == 0) {
            pl3::trace("fsm_ch_state record not found.", 1, ['params' => func_get_args()], 'pl3_fsm');
            return FALSE;
        }

        $upd_res = pl3db::update('fsm', ['is_enabled' => $active_state], "Where mh ='$fsm_mh'", [], $connection_name);
        if (!$upd_res) {
            pl3::trace("fsm_ch_state db_error", 2, ['params' => func_get_args(), 'active_state' => $active_state], 'pl3_fsm');
            return FALSE;
        }
        pl3::trace("fsm_ch_state OK.", 0, ['params' => func_get_args(), 'active_state' => $active_state], 'pl3_fsm');

        if ($rename) {
            self::fsm_rename($fsm_mh);
        }
    }

}

class pl3amqp {

//amqp
//amqp
    private static $amqp_connection;
    private static $amqp_channel;
    private static $amqp_queue;
    private static $amqp_message;
    private static $amqp_sets;

    static function set($key, $val, $connection_name = 'main') {
        self::$amqp_sets[$connection_name][$key] = $val;
//$host, $login, $psw, $vhost, $port,
//var $amqp_port = 5672;
//pl3_amqp::set('host', 'localhost');
//pl3_amqp::set('login', 'nmdev');
//pl3_amqp::set('psw', 'nmdev');
//pl3_amqp::set('port', '5672');
//pl3_amqp::set('vhost', '/');
    }

    static function send($queue_name, $payload, $connection_name = 'main') {
        try {
            $connection = new AMQPConnection();
            $connection->setLogin(self::$amqp_sets[$connection_name]['login']);
            $connection->setPassword(self::$amqp_sets[$connection_name]['psw']);
            $connection->setPort(self::$amqp_sets[$connection_name]['port']);

            try {
                $connection->connect();
            } catch (AMQPConnectionException $exc) {
                pl3::trace('AMQP connect error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'error' => $exc->getMessage()], 'pl3_amqp');
                return FALSE;
            }

            $channel = new AMQPChannel($connection);
            try {
                
            } catch (AMQPExchangeException $exc) {
                pl3::trace('AMQP exchange error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'error' => $exc->getMessage()], 'pl3_amqp');
                return FALSE;
            }

            try {
                $exchange = new AMQPExchange($channel);
                $exchange->setName($queue_name);
                $exchange->setType('fanout');
                $exchange->declareExchange();

                $queue = new AMQPQueue($channel);
                $queue->setName($queue_name);
                $queue->setFlags(AMQP_NOPARAM);
                $queue->declareQueue();

                $queue->bind($queue_name);
                $exchange->publish($payload, $queue_name);


                $connection->disconnect();

                pl3::trace('AMQP send OK ', 0, ['connection_name' => $connection_name, 'queue_name' => $queue_name, 'payload' => $payload], 'pl3_amqp');
                return TRUE;
            } catch (Throwable $exc) {
                pl3::trace('AMQP send error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'error' => $exc->getMessage()], 'pl3_amqp');
                return FALSE;
            }
            $connection->disconnect();
        } catch (Throwable $exc) {
            pl3::trace('AMQP send common error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'error' => $exc->getMessage()], 'pl3_amqp');
            return FALSE;
        }
    }

    static function start_receiver($queue_name, $connection_name = "main") {

        self::$amqp_connection[$connection_name] = new AMQPConnection();
        self::$amqp_connection[$connection_name]->setLogin(self::$amqp_sets[$connection_name]['login']);
        self::$amqp_connection[$connection_name]->setPassword(self::$amqp_sets[$connection_name]['psw']);
        self::$amqp_connection[$connection_name]->setPort(self::$amqp_sets[$connection_name]['port']);

        try {
            self::$amqp_connection[$connection_name]->connect();
        } catch (AMQPConnectionException $exc) {
            pl3::trace('AMQP receiver connect error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'queue_name' => $queue_name, 'error' => $exc->getMessage()], 'pl3_amqp');
            return FALSE;
        }

        try {

            self::$amqp_channel[$connection_name] = new AMQPChannel(self::$amqp_connection[$connection_name]);
            self::$amqp_queue[$connection_name] = new AMQPQueue(self::$amqp_channel[$connection_name]);
            self::$amqp_queue[$connection_name]->setName($queue_name);
            self::$amqp_queue[$connection_name]->setFlags(AMQP_NOPARAM);
            self::$amqp_queue[$connection_name]->declareQueue();

            return TRUE;
        } catch (Throwable $exc) {
            pl3::trace('AMQP receiver  error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'queue_name' => $queue_name, 'error' => $exc->getMessage()], 'pl3_amqp');
            return FALSE;
        }
    }

    static function get($connection_name = "main") {
        try {

            self::$amqp_message[$connection_name] = self::$amqp_queue[$connection_name]->get(AMQP_NOPARAM);
            if (self::$amqp_message[$connection_name] == FALSE) {
                pl3::trace('AMQP get no message: ', 2, ['data' => self::$amqp_sets[$connection_name]], 'pl3_amqp');
                return FALSE;
            }

            try {

                return self::$amqp_message[$connection_name]->getBody();
            } catch (Throwable $exc) {

                pl3::trace('AMQP get body: ', 2, ['data' => self::$amqp_sets[$connection_name], 'error' => $exc->getMessage()], 'pl3_amqp');
            }
        } catch (AMQPException $exc) {
            pl3::trace('AMQP receive error: ', 2, ['data' => self::$amqp_sets[$connection_name], 'error' => $exc->getMessage()], 'pl3_amqp');
        }
        return FALSE;
    }

    static function ack($connection_name = "main") {

        try {
            self::$amqp_queue[$connection_name]->ack(self::$amqp_message[$connection_name]->getDeliveryTag());
        } catch (Throwable $exc) {
            echo $exc->getMessage();
            pl3::trace('AMQP ack error: ', 2, ['connection_name' => $connection_name, 'error' => $exc->getMessage()], 'pl3_amqp');
        }
    }

    static function nack($connection_name = "main") {
        try {
            self::$amqp_queue[$connection_name]->nack(self::$amqp_message[$connection_name]->getDeliveryTag());
        } catch (Throwable $exc) {
            echo $exc->getMessage();
            pl3::trace('AMQP ack error: ', 2, ['connection_name' => $connection_name, 'error' => $exc->getMessage()], 'pl3_amqp');
        }
    }

    static function nack_requeue($connection_name = "main") {
        try {
            self::$amqp_queue[$connection_name]->nack(self::$amqp_message[$connection_name]->getDeliveryTag(), AMQP_REQUEUE);
        } catch (Throwable $exc) {
            echo $exc->getMessage();
            pl3::trace('AMQP ack error: ', 2, ['connection_name' => $connection_name, 'error' => $exc->getMessage()], 'pl3_amqp');
        }
    }

    static function close($connection_name = "main") {


        try {
            self::$amqp_connection[$connection_name]->disconnect();
        } catch (Throwable $exc) {
            echo $exc->getMessage();
            pl3::trace('AMQP nack_close error: ', 2, ['connection_name' => $connection_name, 'error' => $exc->getMessage()], 'pl3_amqp');
        }
    }

}

class pl3check {

    private static $init_status = FALSE;
    private static $cfg;
    private static $status;
    private static $lang_const;
    private static $global_limits; //default limmits
//current
    private static $current_limits;

// internal functions
    private static function init() {


        if (self::$init_status == TRUE) {
            return TRUE;
        }

        self::set_throw_on_error();
//set constants
//setdefautl config
        self::$lang_const['systemerror'] = "System error!";
        self::$lang_const['allowvalues'] = "Allow values";
        self::$lang_const['allowlength'] = "Allow length";
        self::$lang_const['allowlengthbytes'] = "chars.";

        self::$lang_const['minvalue'] = "is small.";
        self::$lang_const['bigvalue'] = "is big.";
        self::$lang_const['shortvalue'] = "is short.";
        self::$lang_const['longvalue'] = "is long.";
        self::$lang_const['invalidvalue'] = "contain invalid data.";
        self::$lang_const['invalidlist'] = "maybe correct, but list is invalid.";
        self::$lang_const['valuenotinlist'] = " value not in list.";
        self::$lang_const['datevalueerror'] = " date incorrect.";
        self::$lang_const['timevalueerror'] = " time incorrect.";
        self::$init_status = TRUE;

        self::$global_limits['minintval'] = -2147483647;
        self::$global_limits['maxintval'] = 2147483647;
        self::$global_limits['minfloatval'] = -3.402823466E+38;
        self::$global_limits['maxfloatval'] = 3.402823466E+38;
        self::$global_limits['minlenstr'] = 0;
        self::$global_limits['maxlenstr'] = 255;
    }

// config and integration
    static function set_show_msg_limits($show_msg_limits = true) {
        self::$cfg['show_msg_limits'] = $show_msg_limits;
    }

    static function set_throw_on_error($throw_on_error = true) {
        self::$cfg['throw_on_error'] = $throw_on_error;
    }

    static function set_messages($msg_arr) {
// load msg array
        if (!is_array($msg_arr)) {
            pl3::status_fail(self::msg('systemerror'), "set_messages input is not array", ['input' => $msg_arr], TRUE);
        }

        if (count($msg_arr) == 0) {
            pl3::status_fail(self::msg('systemerror'), "set_messages input array void", ['input' => $msg_arr], TRUE);
        }

        foreach ($msg_arr as $k => $v) {
            self::$lang_const[$k] = $v;
        }
    }

// service functions
    private static function fail() {
        self::$status = FALSE;
    }

    private static function getlimit($limit_name) {
        if (isset(self::$current_limits[$limit_name])) {
            return self::$current_limits[$limit_name];
        }

        return self::$global_limits[$limit_name];
    }

    private static function formmessage($title, $basic_const, $limit_type = '') {

        $tail = '';
        if (self::$cfg['show_msg_limits']) {
            if ($limit_type == 'int') {
                $tail = self::msg('allowvalues') . ": " . self::getlimit('minintval') . " - " . self::getlimit('maxintval');
            }

            if ($limit_type == 'float') {
                $tail = self::msg('allowvalues') . ": " . self::getlimit('minfloatval') . " - " . self::getlimit('maxfloatval');
            }

            if ($limit_type == 'str') {
                $tail = self::msg('allowlength') . ": " . self::getlimit('minlenstr') . " - " . self::getlimit('maxlenstr') . " " . self::msg('allowlengthbytes');
            }
        }

        return "$title " . self::msg($basic_const) . " $tail";
    }

    private static function msg($const) {
        return self::$lang_const[$const];
    }

// check
    static function integer_val($title, $value, $minintval = null, $maxintval = null) {
        self::init();

        if (!is_null($minintval)) {
            self::minintval($minintval);
        }

        if (!is_null($maxintval)) {
            self::maxintval($maxintval);
        }

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }

// value
        if ($value == '') {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

// cleaning
        $value = filter_var($value, FILTER_VALIDATE_INT);

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

// min value
        if ($value < self::getlimit('minintval')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'int'), "", ['title' => $title, 'value' => $value], FALSE);
            return self::getlimit('minintval');
        }

// max value
        if ($value > self::getlimit('maxintval')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'int'), "", ['title' => $title, 'value' => $value], FALSE);
            return self::getlimit('maxintval');
        }

        return $value;
    }

    static function float_val($title, $value, $minfloatval = null, $maxfloatval = null) {
        self::init();

        if (!is_null($minfloatval)) {
            self::minfloatval($minfloatval);
        }

        if (!is_null($maxfloatval)) {
            self::maxfloatval($maxfloatval);
        }

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }
// value
        if ($value == '') {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return 0;
        }

// cleaning
        $value = filter_var($value, FILTER_VALIDATE_FLOAT);

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

// min value
        if ($value < self::getlimit('minfloatval')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'float'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return self::getlimit('minfloatval');
        }

// max value
        if ($value > self::getlimit('maxfloatval')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'float'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return self::getlimit('maxfloatval');
        }

        return $value;
    }

    static function checkbox_val($title, $value) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }


        if ($value != 'on' && $value != '') {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }


        return $value;
    }

    static function string_val($title, $value, $minlenstr = null, $maxlenstr = null) {
        self::init();

        if (!is_null($minlenstr)) {
            self::minlenstr($minlenstr);
        }

        if (!is_null($maxlenstr)) {
            self::maxlenstr($maxlenstr);
        }


        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }


// min value
        if (strlen($value) < self::getlimit('minlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return $value;
        }

// max value
        if (strlen($value) > self::getlimit('maxlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return substr($value, 0, self::getlimit('maxlenstr'));
        }

        return $value;
    }

    static function string_regexp($title, $value, $regexp, $minlenstr = null, $maxlenstr = null) {
        self::init();

        if (!is_null($minlenstr)) {
            self::minlenstr($minlenstr);
        }

        if (!is_null($maxlenstr)) {
            self::maxlenstr($maxlenstr);
        }

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => $regexp)));

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return '';
        }

// min value
        if (strlen($value) < self::getlimit('minlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return $value;
        }

// max value
        if (strlen($value) > self::getlimit('maxlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return substr($value, 0, self::getlimit('maxlenstr'));
        }

        return $value;
    }

    static function string_smallvar($title, $value, $minlenstr = null, $maxlenstr = null) {
        self::init();

        if (!is_null($minlenstr)) {
            self::minlenstr($minlenstr);
        }

        if (!is_null($maxlenstr)) {
            self::maxlenstr($maxlenstr);
        }

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }


        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => "/^[a-z0-9_\-]+$/")));

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return '';
        }

// min value
        if (strlen($value) < self::getlimit('minlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return $value;
        }

// max value
        if (strlen($value) > self::getlimit('maxlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return substr($value, 0, self::getlimit('maxlenstr'));
        }

        return $value;
    }

    static function string_varname($title, $value, $minlenstr = null, $maxlenstr = null) {
        self::init();

        if (!is_null($minlenstr)) {
            self::minlenstr($minlenstr);
        }

        if (!is_null($maxlenstr)) {
            self::maxlenstr($maxlenstr);
        }

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => "/^[a-zA-Z0-9_\-]+$/")));

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return '';
        }

// min value
        if (strlen($value) < self::getlimit('minlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return $value;
        }

// max value
        if (strlen($value) > self::getlimit('maxlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return substr($value, 0, self::getlimit('maxlenstr'));
        }

        return $value;
    }

    static function string_email($title, $value) {
        self::init();


        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

// cleaning
        $value = filter_var($value, FILTER_VALIDATE_EMAIL);

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return '';
        }

// min value
        if (strlen($value) < self::getlimit('minlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'minvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return $value;
        }

// max value
        if (strlen($value) > self::getlimit('maxlenstr')) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'bigvalue', 'str'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return self::getlimit('maxlenstr');
        }

        return $value;
    }

    static function string_ip4($title, $value) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return '';
        }
        return $value;
    }

    static function string_ip6($title, $value) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }
        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

        return $value;
    }

    static function string_url($title, $value) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_URL);

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], FALSE);
            return 0;
        }

        return $value;
    }

    static function list_val($title, $value, $list) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "input is bool", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if ($value === NULL) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "Input is null", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "Input is array", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($value)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (!is_array($list)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidlist'), "List not array", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (array_key_exists($value, $list)) {
            return TRUE;
        }

        self::fail();
        pl3::status_fail(self::formmessage($title, 'valuenotinlist'), "Not found", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
        return FALSE;
    }

    static function date_date($title, $year, $mon, $day) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($year)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $year], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($year)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $year], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($mon)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $mon], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($mon)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $mon], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($day)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $day], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($day)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $day], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (!checkdate($mon, $day, $year)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'datevalueerror'), "checkdate error", ['title' => $title, 'value' => func_get_args()], self::$cfg['throw_on_error']);
            return FALSE;
        }

        return TRUE;
    }

    static function date_time($title, $hour, $min, $sec) {
        self::init();
        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($hour)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $hour], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($hour)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $hour], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($min)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $min], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($min)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $min], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($sec)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $sec], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($sec)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $sec], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (strtotime("$hour:$min:$sec", time()) === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'timevalueerror'), "", ['title' => $title, 'value' => func_get_args()], self::$cfg['throw_on_error']);
            return FALSE;
        }
        return TRUE;
    }

    static function date_datetime($title, $year, $mon, $day, $hour, $min, $sec) {
        self::init();

        if ($value === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $value], self::$cfg['throw_on_error']);
            return '';
        }

        if (is_array($year)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $year], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($year)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $year], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($mon)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $mon], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($mon)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $mon], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($day)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $day], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($day)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $day], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($hour)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $hour], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($hour)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $hour], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($min)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $min], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($min)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $min], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_array($sec)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $sec], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (is_object($sec)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'invalidvalue'), "", ['title' => $title, 'value' => $sec], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (!checkdate($mon, $day, $year)) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'datevalueerror'), "checkdate error", ['title' => $title, 'value' => func_get_args()], self::$cfg['throw_on_error']);
            return FALSE;
        }

        if (strtotime("$hour:$min:$sec", time()) === FALSE) {
            self::fail();
            pl3::status_fail(self::formmessage($title, 'timevalueerror'), "", ['title' => $title, 'value' => func_get_args()], self::$cfg['throw_on_error']);
            return FALSE;
        }


        return TRUE;
    }

//limits
    static function minintval($val) {
        self::$current_limits['minintval'] = $val;
    }

    static function maxintval($val) {
        self::$current_limits['maxintval'] = $val;
    }

    static function minfloatval($val) {
        self::$current_limits['minfloatval'] = $val;
    }

    static function maxfloatval($val) {
        self::$current_limits['maxfloatval'] = $val;
    }

    static function minlenstr($val) {
        self::$current_limits['minlenstr'] = $val;
    }

    static function maxlenstr($val) {
        self::$current_limits['maxlenstr'] = $val;
    }

//service functions
    static function status() {
        return self::$status;
    }

    static function limts_reset() {
        self::$current_limits = [];
    }

    static function status_reset() {
        self::$status = TRUE;
    }

    static function reset() {
        self::limts_reset();
        self::status_reset();
    }

}

class pl3tools {

    static function get_home_url() {
        if (pl3::core_get('home') != '') {
            return pl3::core_get('home');
        }
        return 'http://' . $_SERVER['HTTP_HOST'] . '/';
    }

    static function get_ip() {
        return getenv('REMOTE_ADDR');
    }

    static function oa($arr) {
        pl3::o(pl3tools::ae($arr));
    }

    static function ae($arr, $keys = array(), $l = 0) {
        $out = '';
        if (!is_array($arr)) {
            return $arr;
        }

        while (list( $maskey, $masval ) = each($arr)) {
            $keys[$l] = $maskey;
            if (is_array($masval)) {
                $out .= self::ae($masval, $keys, $l + 1);
            } else {
                for ($i = 0; $i < count($keys); $i++) {
                    $out .= '[' . $keys[$i] . ']';
                }
                if (is_object($masval)) {
                    $out .= "Class:" . get_class($masval) . "<BR>";
                } else {
                    $out .= ":$masval<br>";
                }
            }
        }

        return $out;
    }

    static function ae_extend($arr, $keys = array(), $l = 0, $tail = '', $head = '') {
        $out = '' . $head;
        if (!is_array($arr)) {
            return $arr;
        }


        while (list( $maskey, $masval ) = each($arr)) {
            $keys[$l] = $maskey;
            if (is_array($masval)) {
                $out .= self::ae_extend($masval, $keys, $l + 1, $tail);
            } else {
                for ($i = 0; $i < count($keys); $i++) {
                    $out .= '[' . $keys[$i] . ']';
                }
                if (is_object($masval)) {
                    $out .= "Class:" . get_class($masval) . $tail;
                } else {
                    $out .= ":$masval $tail";
                }
            }
        }

        return $out;
    }

    static function form_date($ts, $type = '0') {
//0 - full
//1 - day only
// GM edition
        if ($type === 'gm0') {
            return gmdate("d.m.y H:i:s", $ts);
        }
        if ($type === 'gm1') {
            return gmdate("d.m.y", $ts);
        }
        if ($type === 'gm2') {
            return gmdate("H:i:s", $ts);
        }

        if ($type === 'g3') {
            return gmdate("d.m.Y", $ts);
        }

        if ($type === 'ms') {
            $min = floor($ts / 60);
            $sec = $ts - $min * 60;
            return "$min:$sec";
        }
        if ($type === 'min') {
            $min = floor($ts / 60);
            return "$min";
        }


        if ($type == 0) {
            return date("d.m.y H:i:s", $ts);
        }
        if ($type == 1) {
            return date("d.m.y", $ts);
        }
        if ($type == 2) {
            return date("H:i:s", $ts);
        }

        if ($type == 3) {
            return date("d.m.Y", $ts);
        }
    }

    static function date_to_ts($str, $format) {
//($str_a[1], "%d.%m.%y %H:%M:%S");
        $arr = strptime($str, $format);
        return mktime($arr['tm_hour'], $arr['tm_min'], $arr['tm_sec'], $arr['tm_mon'] + 1, $arr['tm_mday'], $arr['tm_year'] + 1900);
    }

    static function megahash($str) {
        return md5($str) . sha1($str);
    }

    static function megahash_uniq($salt = '') {
        $str = time() . microtime() . $salt . rand(0, PHP_INT_MAX);
        return md5($str) . sha1($str);
    }

    static function url_transit($name_arr, $is_first = 1) {
        $out = '';

        foreach ($name_arr as $k => $v) {
            if (!is_int($k)) {
                $out .= "&$k=$v";
            } else {
                if (pl3::gpc($v) != '') {
                    $out .= "&$v=" . pl3::gpc($v);
                }
            }
        }

        if ($is_first == 1) {
            $out[0] = '?';
        }

        if ($is_first == 2) {

            $out[0] = '?';
            $out = '{home}' . $out;
//o($out);
        }

        if (is_array($out)) {
            o("!!!");
        }

        return $out;
    }

    static function url_transit_post($name_arr) {
        $out = '';
        foreach ($name_arr as $k => $v) {

            if (!is_int($k)) {
                $out .= "<input type='hidden' name='$k' value='" . $v . "'>\r\n";
            } else {
                if (pl3::gpc($v) != '') {
                    $out .= "<input type='hidden' name='$v' value='" . pl3::gpc($v) . "'>\r\n";
                }
            }
        }
        return $out;
    }

    static function url_get($in_arr, $is_first = 0) {
        $out = '';
        foreach ($in_arr as $k => $v) {
            $out .= "&$k=$v";
        }

        if ($is_first == 1) {
            $out[0] = '?';
        }
        return $out;
    }

    static function form_memory($size, $type = 2) {

        if ($type == 0) {
            $iec = array('байт', 'Килобайт', 'Мегабайт', 'Гигабайт', 'Терабайт',
                'Петабайт', 'Эксабайт');
        }
        if ($type == 1) {
            $iec = array('б', 'Кб', 'Мб', 'Гб', 'Тб', 'Пб', 'Эб');
        }
        if ($type == 2) {
            $iec = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');
        }
        $i = 0;
        while (($size / 1024) > 1) {
            $size = $size / 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $iec[$i];
    }

}

class pl3db {

    private static $db_resources;
    private static $db_prefixes;
    private static $db_noslashes;
    private static $db_cursors;
    private static $db_query_status;
    private static $db_query_error;
    private static $cfg;

    //integration
    static function set($key, $value) {
        self::$cfg[$key] = $value;
    }

    static function get($key) {
        return self::$cfg[$key];
    }

    //queries

    static function query($query, $params = [], $connection_name = 'main') {
        if (!is_array($params)) {
            $params = [];
        }

        pl3::timer_stat('db_query');

        if (!isset(self::$db_resources[$connection_name])) {
            self::on_error('Wrong connection name', ['input' => func_get_args()], 'db', ['timer_name' => 'db_query'], $user_message);
            return FALSE;
        }

        self::$db_query_status[$connection_name] = FALSE;

        try {
            self::$db_cursors[$connection_name] = self::$db_resources[$connection_name]->query($query);
            pl3::trace('DB query', 1, ['input' => func_get_args(), 'query' => $query], 'db', ['timer_name' => 'db_query']);
            self::$db_query_status[$connection_name] = TRUE;
            return TRUE;
        } catch (Exception $e) {
            self::on_throw($e, func_get_args());
            return FALSE;
        }
    }

    static function update($tb, $feelds, $tail = '', $params = array(), $connection_name = 'main') {

        // подготавливаем поля
        if (!is_array($feelds)) {
            $qr = $feelds;
        } else {
            $updates = array();
            foreach ($feelds as $key => $value) {
                if (!self::$db_noslashes) {
                    $value = self::$db_resources[$connection_name]->quote($value);
                }

                $updates[] = "$key = $value";
            }

            $qr = implode(', ', $updates);
        }

        // Reat table name
        $table_name = self::get_prefix($connection_name) . $tb;

        // Preparing query
        $query = "UPDATE $table_name SET $qr $tail";

        // executing
        $qr_result = self::query($query, $params, $connection_name);
        return $qr_result;
    }

    static function insert($tb, $feelds, $params = array(), $connection_name = 'main') {
        while (list($maskey, $masval) = each($feelds)) {

            $i++;
            // no slashes mode check
            if (!self::$db_noslashes) {
                $masval = self::$db_resources[$connection_name]->quote(addslashes($masval));
            }

            if ($i < count($feelds)) {
                $str1 .= "$maskey, ";
                $str2 .= "$masval, ";
            } else {
                $str1 .= " $maskey ";
                $str2 .= "$masval ";
            }
        }

        // Reat table name
        $table_name = self::get_prefix($connection_name) . $tb;

        // Preparing query
        $query = "INSERT INTO $table_name ($str1) VALUES ($str2)";

        // executing
        $qr_result = self::query($query, $params, $connection_name);

        return $qr_result;
    }

    static function delete($tb, $tail, $params = array(), $connection_name = 'main') {

        // Reat table name
        $table_name = self::get_prefix($connection_name) . $tb;

        // Preparing query
        $query = "DELETE FROM $table_name $tail";

        // executing
        $qr_result = self::query($query, $params, $connection_name);

        return $qr_result;
    }

    static function truncate($tb, $params = array(), $connection_name = 'main') {

        // Reat table name
        $table_name = self::get_prefix($connection_name) . $tb;

        // Preparing query
        $query = "truncate table $table_name";

        // executing
        $qr_result = self::query($query, $params, $connection_name);

        return $qr_result;
    }

    static function select($tb, $feelds = '', $tail = '', $params = array(), $connection_name = 'main') {
        pl3::timer_stat('db_select');

        // Preparing feelds

        if ($feelds == '') {
            $f_list = '*';
        } else
        if (is_array($feelds)) {
            for ($i = 0; $i < count($feelds); $i++) {
                if ($i == count($feelds) - 1) {
                    $f_list .= $feelds[$i] . '';
                } else {
                    $f_list .= $feelds[$i] . ', ';
                }
            }
        } else {
            $f_list = $feelds;
        }

        // Read table name
        $table_name = self::get_prefix($connection_name) . $tb;

        // Preparing query
        $query = "SELECT $f_list FROM $table_name $tail";

        // executing
        $qr_result = self::query($query, $params, $connection_name);
        return $qr_result;
    }

    static function select_count($tb, $tail = '', $param = [], $connection_name = 'main') {
        return self::select($tb, 'count(*) as count', $tail, $param, $connection_name);
    }

    //result

    static function fetch_result_array($connection_name = 'main') {
        if (!isset(self::$db_cursors[$connection_name])) {
            self::on_error('Cursor not found', ['input' => func_get_args()]);
            return FALSE;
        }

        try {
            while ($result_row = self::$db_cursors[$connection_name]->fetch(PDO::FETCH_ASSOC)) {
                $result[] = array_map('stripslashes', $result_row);
            }
            return $result;
        } catch (PDOException $e) {
            self::on_throw($e);
            return FALSE;
        }
    }

    static function fetch_result_row($connection_name = 'main') {
        if (!isset(self::$db_cursors[$connection_name])) {
            pl3::trace('No result', 2, ['input' => func_get_args()], 'db');
            return FALSE;
        }

        try {
            $result_row = self::$db_cursors[$connection_name]->fetch(PDO::FETCH_ASSOC);
            if ($result_row) {
                return array_map('stripslashes', $result_row);
            }
        } catch (PDOException $e) {
            self::on_throw($e);
            return FALSE;
        }
    }

    static function fetch_result_count($connection_name = 'main') {
        if (!isset(self::$db_cursors[$connection_name])) {
            pl3::trace('No result', 2, ['input' => func_get_args()], 'db');
            return FALSE;
        }

        try {
            $res_string = self::$db_cursors[$connection_name]->fetch(PDO::FETCH_ASSOC);
            return $res_string['count'];
        } catch (PDOException $e) {
            self::on_throw($e);
            return FALSE;
        }
    }

    static function get_query_status($connection_name = 'main') {
        return self::$db_query_status[$connection_name];
    }

    static function get_query_error($implode = '') {
        if (is_array(self::$db_query_error)) {
            return implode($implode, self::$db_query_error);
        }
        return '';
    }

    static function get_query_error_array() {
        return self::$db_query_error;
    }

    static function get_num_rows($connection_name = 'main') {
        try {
            $result = self::$db_cursors[$connection_name]->rowCount();
            return $result;
        } catch (PDOException $e) {
            self::on_throw($e);
            return 0;
        }
    }

    //connect
    static function connect_pdo($dsn, $user = NULL, $pass = NULL, $opt = array(), $connection_name = 'main') {


        //$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $opt[PDO::ATTR_DEFAULT_FETCH_MODE] = isset($opt[PDO::ATTR_DEFAULT_FETCH_MODE]) ?: PDO::FETCH_ASSOC;
        $opt[PDO::ATTR_ERRMODE] = isset($opt[PDO::ATTR_ERRMODE]) ?: PDO::ERRMODE_EXCEPTION;

        try {
            pl3::timer_stat('db_connect');
            self::set_connection_resource(new PDO($dsn, $user, $pass, $opt), $connection_name);
        } catch (Throwable $e) {
            self::on_throw($e);
            return FALSE;
        }

        pl3::trace('DB connection ok', 0, ['input' => func_get_args()], 'db', ['timer_name' => 'db_connect']);
        return TRUE;
    }

    static function connect_mysql($host, $user, $pass, $db_name, $opt = array(), $connection_name = 'main') {
        if (in_array("mysql", PDO::getAvailableDrivers(), TRUE)) {
            $dsn = "mysql:host=$host;dbname=$db_name";
            return self::connect_pdo($dsn, $user, $pass, $opt, $connection_name);
        } else {
            self::on_error("No driver MySQL");
            return FALSE;
        }
    }

    static function connect_pgsql($host, $user, $pass, $db_name, $opt = array(), $connection_name = 'main') {
        if (in_array("pgsql", PDO::getAvailableDrivers(), TRUE)) {
            $dsn = "pgsql:host=$host;dbname=$db_name";
            return self::connect_pdo($dsn, $user, $pass, $opt, $connection_name);
        } else {
            self::on_error("No driver PgSQL");
            return FALSE;
        }
    }

    static function connect_sqlite($path, $connection_name = 'main') {
        if (in_array("sqlite", PDO::getAvailableDrivers(), TRUE)) {
            $dsn = "sqlite:$path";
            return self::connect_pdo($dsn, $user, $pass, $opt, $connection_name);
        } else {
            self::on_error("No driver SqLite");
            return FALSE;
        }
    }

    //other
    static function set_error($msg) {
        self::$db_query_error[] = $msg;
    }

    static function set_prefix($prefix, $connection_name = 'main') {
        self::$db_prefixes[$connection_name] = $prefix;
    }

    static function get_prefix($connection_name = 'main') {
        return self::$db_prefixes[$connection_name];
    }

    ///private
    private static function pdo_cursor($connection_name = 'main') {
        return self::$db_cursors[$connection_name];
    }

    private static function set_connection_resource($res, $connection_name = 'main') {
        self::$db_resources[$connection_name] = $res;
    }

    private static function get_connection_resource($connection_name = 'main') {
        return self::$db_resources[$connection_name];
    }

    private static function set_noslashes($return_to_slashes = FALSE) {
        if ($return_to_slashes) {
            self::$db_noslashes = FALSE;
        } else {
            self::$db_noslashes = TRUE;
        }
    }

    private static function on_throw(Throwable $t) {
        self::set_error($t->getMessage());
        pl3::status_fail("Database error (Code 101)", $t->getMessage(), $t->getTrace(), true);
        pl3::trace("DB on_throw() :" . $t->getMessage(), 2, $t->getTrace(), 'db');
    }

    private static function on_error($admin_message = '', $data = [], $user_message = '') {
        if ($user_message == '') {
            $user_message = 'Database error  (Code 102)';
        }
        self::set_error($admin_message);
        pl3::status_fail($user_message, $admin_message, $data, TRUE);
    }

}

class pl3usr {

    // usr data
    static $usr_data;
    static $usr_connection_name = 'main';
    static $usr_last_error;
    static $usr_perms_default;
    static $usr_user_perms;
    static $usr_auth_status;

// USR
// usr_perm_groups {'user_mh','group_code'}
// usr_perm_users{'user_mh','perm_code','allow'}
    static function set_db_connection_name($connection_name = 'main') {
        self::$usr_connection_name = $connection_name;
    }

    static function get_db_connection_name() {
        return self::$usr_connection_name;
    }

    static function reg($email, $password, $is_enabled = TRUE) {
// check if email exists,
        try {
            pl3db::select_count('usr_users', "WHERE email='$email'", [], self::get_db_connection_name());
            $rows = pl3db::fetch_result_count(self::get_db_connection_name());
            if ($rows > 0) {
                pl3::trace('Email exists', 2, func_get_args(), 'pl3_usr');
                pl3::status_fail("User exists", "User found", ['rows' => $rows, 'input' => func_get_args()], TRUE);
                return FALSE;
            }
            // create record
            $to_db = [];
            $to_db['mh'] = pl3tools::megahash_uniq('pl3_user');
            $to_db['email'] = $email;
            $to_db['pass_hash_mh'] = pl3tools::megahash($password);
            $to_db['is_enabled'] = $is_enabled;

            return pl3db::insert('usr_users', $to_db, [], self::get_db_connection_name());
        } catch (Exception $ex) {
            pl3::status_catch($ex);
            return FALSE;
        }
    }

    static function del($user_mh) {
        try {
            //clear sessions
            pl3db::delete('usr_sessions', "WHERE user_mh = '$user_mh'", [], self::get_db_connection_name());

            //clear user from table
            pl3db::delete('usr_users', "WHERE mh = '$user_mh'", [], self::get_db_connection_name());
            return TRUE;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function logout($auth_key) {
        try {
            // delete record in table
            $session = self::get_session();
            pl3::trace('Self logout', 1, ['auth_key' => $auth_key, 'Sesion' => $session], 'pl3_usr');
            if ($session['auth_key'] == $auth_key) {
                self::$usr_data = [];
                self::$usr_auth_status = FALSE;
                pl3::gpc('pl3_auth_key', '');
            }

            pl3db::delete('usr_sessions', "WHERE auth_key = '$auth_key'", [], self::get_db_connection_name());
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function check_psw_by_mh($user_mh, $password) {

        try {
            $pass_hash_mh = pl3tools::megahash($password);
            pl3db::select('usr_users', '', "Where mh = '$user_mh' and pass_hash_mh = '$pass_hash_mh'", [], self::get_db_connection_name());
            $row = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($row) == 0) {
                pl3::status_fail("User not found", "", ['input' => func_get_args()], TRUE);
                return FALSE;
            }

            return TRUE;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function upd_psw($user_mh, $new_psw) {
        try {
            pl3db::select('usr_users', '', "Where mh = '$user_mh' and pass_hash_mh = '$pass_hash_mh'", [], self::get_db_connection_name());
            $row = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($row) == 0) {
                pl3::status_fail("User not found", "", ['input' => func_get_args()], TRUE);
                return FALSE;
            }
            // update password in user table
            pl3db::update('usr_users', ['pass_hash_mh' => pl3tools::megahash($new_psw)], "WHERE mh='$user_mh'", [], self::get_db_connection_name());
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function get_mh() {
        if (isset(self::$usr_data['data']['mh'])) {
            return self::$usr_data['data']['mh'];
        }

        return FALSE;
        // return mh of current user of false
    }

    static function get_session() {
        if (isset(self::$usr_data['session'])) {
            return self::$usr_data['session'];
        }
        return FALSE;
    }

    static function ch_auth($cookie_name = 'pl3_auth_key') {
        try {
            // read auth_key from cookie
            $auth_key = pl3::gpc($cookie_name);

            if (strlen($auth_key) == 0) {
                return FALSE;
            }

            if (self::$usr_auth_status == TRUE) {
                return TRUE;
            }

            pl3check::status_reset();
            pl3check::set_throw_on_error();
            pl3check::minlenstr(72);
            pl3check::maxlenstr(72);
            pl3check::string_smallvar("Auth key", $auth_key);
            if (!pl3check::status()) {
                pl3::status_fail("Wrong auth key", "", ['key' => $auth_key, 'cookie' => $cookie_name], TRUE);
                return FALSE;
            }


            // search record in session table

            pl3db::select('usr_sessions', '', "WHERE auth_key='$auth_key'", [], self::get_db_connection_name());
            if (pl3db::get_num_rows(self::get_db_connection_name()) == 0) {
                setcookie($cookie_name, "");
                pl3::trace('Session not found', 1, ['key' => $auth_key, 'cookie' => $cookie_name], 'pl3_usr');
                return FALSE;
            }

            $result = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($result) > 1) {
                pl3usr::logout($auth_key);
                pl3::trace('Wrong result count. Emergency logout', 1, $result, 'pl3_usr');
                return FALSE;
            }

            // if ok - load user_data and return true
            self::$usr_data['session'] = $result[0];
            $user_mh = self::$usr_data['session']['user_mh'];

            pl3db::select('usr_users', '', "WHERE  mh = '$user_mh' ", [], self::get_db_connection_name());

            $user_result = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($user_result) == 0) {
                pl3usr::logout($auth_key);
                pl3::status_fail("User not found", "Session found, but user not! Forcelogout!", ['auth_key' => $auth_key, 'user_mh' => $user_mh, 'session_result' => $result[0]], TRUE);
                return FALSE;
            }
            self::$usr_data['data'] = $user_result[0];

            if (self::$usr_data['data']['mh'] != '') {
                pl3::trace('Session ok! Updating...', 0, ['auth_key' => $auth_key, 'user_mh' => $user_mh, 'session_mh' => $session_mh], 'pl3_usr');
                $session_mh = self::$usr_data['session']['mh'];
                pl3db::update('usr_sessions', ['time_last' => time()], "WHERE  mh = '$session_mh' ", [], self::get_db_connection_name());
                self::$usr_auth_status = TRUE;
                return TRUE;
            }

            pl3::trace('Anomaly point', 3, ['cookie_name' => $cookie_name, 'auth_key' => $auth_key], 'pl3_usr');
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function login($email, $password, $cookie_timeout = 3600 * 24, $cookie_name = 'pl3_auth_key') {
        try {
            $pass_hash_mh = pl3tools::megahash($password);
            pl3db::select('usr_users', '', "Where email = '$email' and pass_hash_mh = '$pass_hash_mh'", [], self::get_db_connection_name());

            $row = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($row) == 0) {
                pl3::trace('usr_login: user not found', 1, ['input' => func_get_args()], 'pl3_usr');
                return FALSE;
            }
            $todb['mh'] = pl3tools::megahash_uniq();
            $todb['auth_key'] = pl3tools::megahash_uniq();
            $todb['user_mh'] = $row[0]['mh'];
            $todb['time_login'] = time();
            $todb['time_last'] = time();

            pl3db::insert('usr_sessions', $todb, [], self::get_db_connection_name());
            setcookie($cookie_name, $todb['auth_key'], time() + $cookie_timeout);
            pl3::gpc($cookie_name, $todb['auth_key']);
            pl3::trace('usr_login: Login ok', 0, ['input' => func_get_args(), 'todb' => $todb], 'pl3_usr');
            return self::ch_auth($cookie_name);
        } catch (Exception $ex) {
            pl3::status_catch($ex);
            return FALSE;
        }
    }

    static function logout_all($user_mh) {
        //send delete query to db
        try {
            pl3db::delete('usr_sessions', "WHERE user_mh = '$user_mh'", [], self::get_db_connection_name());
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function disable_user($user_mh) {
        //update 
        try {
            pl3db::update('usr_users', ['is_enabled' => '0'], "WHERE mh='$user_mh'", [], self::get_db_connection_name());
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function enable_user($user_mh) {
        //update 
        try {
            pl3db::update('usr_users', ['is_enabled' => '1'], "WHERE mh='$user_mh'", [], self::get_db_connection_name());
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function get_by_mh($user_mh) {
        //select from users
        try {
            pl3db::select('usr_users', '', "WHERE  mh = '$user_mh' ", [], self::get_db_connection_name());
            $row = pl3db::fetch_result_row(self::get_db_connection_name());
            if (is_array($row)) {
                // if ok - select session, merging and return array
                $out['data'] = $row;
                $state = pl3db::select('usr_sessions', '', "WHERE  user_mh = '$user_mh' ", [], self::get_db_connection_name());
                if ($state) {
                    $out['sessions'] = pl3db::fetch_result_array(self::get_db_connection_name());
                }
                return $out;
            }

            // if not found - return false
            return FALSE;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function get_by_email($email) {
        try {
            //select from users
            pl3db::select('usr_users', '', "WHERE  email = '$email' ", [], self::get_db_connection_name());

            $row = pl3db::fetch_result_row(self::get_db_connection_name());
            if (is_array($row)) {
                // if ok - select session, merging and return array
                $out['data'] = $row;
                $state = pl3db::select('usr_sessions', '', "WHERE  user_mh = '$user_mh' ", [], self::get_db_connection_name());
                if ($state) {
                    $out['sessions'] = pl3db::fetch_result_array(self::get_db_connection_name());
                }
                return $out;
            }
            return FALSE;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    // usr_perm

    static function return_perm_arrays() {
        return ['defaults' => self::$usr_perms_default, 'user_perms' => self::$usr_user_perms];
    }

    static function perm_user_perm_set($user_mh, $perm_code, $allow = 1) {
        try {
            pl3db::select_count('usr_perm_users', "WHERE user_mh='$user_mh' and perm_code='$perm_code'", [], self::get_db_connection_name());
            if (pl3db::fetch_result_count() == 0) {
                pl3db::insert('usr_perm_users', ['user_mh' => $user_mh, 'perm_code' => $perm_code, 'allow' => $allow], [], self::get_db_connection_name());
                pl3::trace('usr_perm_user_perm_set: permission inserted', 0, ['input' => func_get_args(), 'err' => pl3db::get_query_error(self::get_db_connection_name())], 'pl3_usr');
                return true;
            }
            pl3db::update('usr_perm_users', ['allow' => $allow], "WHERE user_mh='$user_mh' and perm_code='$perm_code'", [], self::get_db_connection_name());
            pl3::trace('usr_perm_user_perm_set: permission updated', 0, ['input' => func_get_args(), 'err' => pl3db::get_query_error(self::get_db_connection_name())], 'pl3_usr');
            return true;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function perm_user_perm_rm($user_mh, $perm_code) {
        try {
            pl3db::delete('usr_perm_users', "WHERE user_mh='$user_mh' and perm_code='$perm_code'", [], self::get_db_connection_name());
            pl3::trace('usr_perm_user_perm_rm: permission removed', 0, ['input' => func_get_args(), 'err' => pl3db::get_query_error(self::get_db_connection_name())], 'pl3_usr');
            return true;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function perm_user_group_set($user_mh, $group_code) {
        try {
            pl3db::select_count('usr_perm_groups', "WHERE user_mh='$user_mh' and group_code='$group_code'", [], self::get_db_connection_name());
            if (pl3db::fetch_result_count() == 0) {
                pl3db::insert('usr_perm_groups', ['user_mh' => $user_mh, 'group_code' => $group_code], [], self::get_db_connection_name());
                pl3::trace('usr_perm_user_group_set: permission inserted', 0, ['input' => func_get_args()], 'pl3_usr');
                return true;
            }
            pl3::trace('usr_perm_user_group_set: permission exists', 0, ['input' => func_get_args()], 'pl3_usr');
            return true;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function perm_user_group_rm($user_mh, $group_code) {
        try {
            pl3db::delete('usr_perm_groups', "WHERE user_mh='$user_mh' and group_code='$group_code'", [], self::get_db_connection_name());
            pl3::trace('usr_perm_user_group_rm: permission removed', 0, ['input' => func_get_args()], 'pl3_usr');
            return true;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function perm_load() {


        try {
            $user_mh = self::get_mh();

            if (strlen($user_mh) != 72) {
                pl3::trace('usr_perm_load bad mh', 2, ['user_mh' => $user_mh, 'err' => pl3db::get_query_error(self::get_db_connection_name())], 'pl3_usr');
                return FALSE;
            }

            //load user groups
            pl3db::select('usr_perm_groups', '', "WHERE user_mh = '$user_mh'", '', self::get_db_connection_name());
            $sel_result = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($sel_result) == 0) {
                pl3::trace('usr_perm_load: groups not found', 1, [], 'pl3_usr');
                $sel_result[]['group_code'] = 'guest';
            }

            if (count($sel_result) > 0) {
                foreach ($sel_result as $group) {
                    pl3::trace('usr_perm_load: loading perm group', 0, ['group' => $group['group_code']], 'pl3_usr');

                    foreach (self::$usr_perms_default[$group['group_code']] as $perm_code => $allow) {

                        if (!isset(self::$usr_user_perms[$perm_code])) {
                            self::$usr_user_perms[$perm_code] = $allow;
                        } else {
                            if (self::$usr_user_perms[$perm_code] == 1 && self::$usr_perms_default[$group['group_code']][$perm_code] == 0) {
                                self::$usr_user_perms[$perm_code] = 0;
                            }
                        }

                        pl3::trace('usr_perm_load: set perm', 0, ['group' => $group['group_code'], 'perm_code' => $perm_code, 'allow' => $allow, 'result' => self::$usr_user_perms[$perm_code]], 'pl3_usr');
                    }
                }
            }
            pl3::trace('usr_perm_load: groups loaded', 0, ['perms' => self::$usr_user_perms[$perm_code]], 'pl3_usr');

            //load user perms
            pl3db::select('usr_perm_users', '', "WHERE user_mh = '$user_mh'", '', self::get_db_connection_name());
            $usr_perms_res = pl3db::fetch_result_array(self::get_db_connection_name());
            if (count($usr_perms_res) > 0) {
                foreach ($usr_perms_res as $usr_perm) {
                    self::$usr_user_perms[$usr_perm['perm_code']] = $usr_perm['allow'];
                    pl3::trace('usr_perm_load: setuser perm', 0, ['perm_code' => $usr_perm['perm_code'], 'allow' => $usr_perm['allow'], 'result' => self::$usr_user_perms[$perm_code]], 'pl3_usr');
                }
            }

            pl3::trace('usr_perm_load: user perms loaded', 0, ['perms' => self::$usr_user_perms[$perm_code]], 'pl3_usr');
            return TRUE;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    static function perm_check($perm_code) {
// defaut group: guest 

        if (!isset(self::$usr_user_perms[$perm_code])) {
            pl3::trace('usr_perm_check: permission not found. Access denied', 0, ['input' => func_get_args()], 'pl3_usr');
            return FALSE;
        }

        if (self::$usr_user_perms[$perm_code] == 1) {
            pl3::trace('usr_perm_check: permission ok. Access graned', 0, ['input' => func_get_args()], 'pl3_usr');
            return TRUE;
        }

        pl3::trace('usr_perm_check: permission not allow. Access denied', 0, ['input' => func_get_args()], 'pl3_usr');
        return FALSE;
    }

    static function perm_group_set($group_code, $perm_code, $allow = true) {
        self::$usr_perms_default[$group_code][$perm_code] = $allow;
    }

}

function arr_explore($arr, $keys = array(), $l = 0) {
    $out = '';
    if (!is_array($arr)) {
        return $arr;
    }

    while (list( $maskey, $masval ) = each($arr)) {
        $keys[$l] = $maskey;
        if (is_array($masval)) {
            $out .= arr_explore($masval, $keys, $l + 1);
        } else {
            for ($i = 0; $i < count($keys); $i++) {
                $out .= '[' . $keys[$i] . ']';
            }
            if (is_object($masval)) {
                $out .= "Class:" . get_class($masval) . "<BR>";
            } else {
                $out .= ":$masval<br>";
            }
        }
    }

    return $out;
}
