<?php

/**
 * JMAP lib
 * @package modules
 * @subpackage imap
 */

/**
 * public interface to JMAP commands
 * @subpackage imap/lib
 */
class Hm_JMAP {

    private $api;
    private $session;
    private $api_url;
    private $download_url;
    private $upload_url;
    private $account_id;
    private $headers;
    private $delim = '.';
    private $state = 'disconnected';
    private $requests = array();
    private $responses = array();
    private $folder_list = array();
    private $streaming_msg = '';
    private $msg_part_id = 0;
    private $append_mailbox = false;
    private $append_seen = false;
    private $append_result = false;
    private $sorts = array(
        'ARRIVAL' => 'receivedAt',
        'FROM' => 'from',
        'TO' => 'to',
        'SUBJECT' => 'subject',
        'DATE' => 'sentAt'
    );
    private $mod_map = array(
        'READ' => array('$seen', true),
        'UNREAD' => array('$seen', NULL),
        'FLAG' => array('$flagged', true),
        'UNFLAG' => array('$flagged', NULL),
        'ANSWERED' => array('$answered', true),
        'UNANSWERED' => array('$answered', NULL)
    );
    private $default_caps = array(
        'urn:ietf:params:jmap:core',
        'urn:ietf:params:jmap:mail'
    );

    public $selected_mailbox;
    public $folder_state;
    public $use_cache = true;

    /** 
     * PUBLIC INTERFACE
     */

    public function __construct() {
        $this->api = new Hm_API_Curl();
    }

    public function get_mailbox_status($mailbox, $args=array('UNSEEN', 'UIDVALIDITY', 'UIDNEXT', 'MESSAGES', 'RECENT')) {
    }

    public function append_start($mailbox, $size, $seen=true) {
        $this->append_mailbox = $mailbox;
        $this->append_seen = $seen;
        return true;
    }

    public function append_feed($string) {
        $upload_url = str_replace('{accountId}', $this->account_id, $this->upload_url);
        $res = $this->send_command($upload_url, array(), 'POST', $string, array(2 => 'Content-Type: multipart/form-data'));
        if (!is_array($res) || !array_key_exists('blobId', $res)) {
            return false;
        }
        $blob_id = $res['blobId'];
        $methods = array(array(
            'Email/import',
            array(
                'accountId' => $this->account_id,
                'emails' => array(1 => array(
                    'mailboxIds' => array($this->folder_name_to_id($this->append_mailbox) => true),
                    'blobId' => $blob_id
                ))
            ),
            'af'
        ));
        /* TODO: figure out blobId issue */
    }

    public function append_end() {
        $res = $this->append_result;
        $this->append_result = false;
        $this->append_mailbox = false;
        $this->append_seen = false;
        return $res;
    }

    public function start_message_stream($uid, $message_part) {

        $struct = $this->get_message_structure($uid);
        $part_struct = $this->search_bodystructure($struct, array('imap_part_number' => $message_part));
        $blob_id = false;
        $name = false;
        $type = false;
        if (is_array($part_struct) && array_key_exists($message_part, $part_struct)) {
            if (array_key_exists('blob_id', $part_struct[$message_part])) {
                $blob_id = $part_struct[$message_part]['blob_id'];
            }
            if (array_key_exists('name', $part_struct[$message_part])) {
                $name = $part_struct[$message_part]['name'];
            }
            else {
                $name = sprintf('message_%s', $message_part);
            }
            if (array_key_exists('type', $part_struct[$message_part])) {
                $type = $part_struct[$message_part]['type'];
            }
        }
        if (!$name || !$blob_id || !$type) {
            return 0;
        }
        $download_url = str_replace(
            array('{accountId}', '{blobId}', '{name}', '{type}'),
            array(
                urlencode($this->account_id),
                urlencode($blob_id),
                urlencode($name),
                urlencode('application/octet-stream')
            ),
            $this->download_url
        );
        $this->api->format = 'binary';
        $this->streaming_msg = $this->send_command($download_url, array(), 'GET');
        $this->api->format = 'json';
        return strlen($this->streaming_msg);
    }

    public function read_stream_line($size=1024) {
        $res = $this->streaming_msg;
        $this->streaming_msg = false;
        return $res;
    }

    public function create_mailbox($mailbox) {
        list($name, $parent) = $this->split_name_and_parent($mailbox);
        $methods = array(array(
            'Mailbox/set',
            array(
                'accountId' => $this->account_id,
                'create' => array(NULL => array('parentId' => $parent, 'name' => $name))
            ),
            'cm'
        ));
        $created = $this->send_and_parse($methods, array(0, 1, 'created'), array());
        $this->reset_folders();
        return $created && count($created) > 0;
    }

    public function delete_mailbox($mailbox) {
        $ids = array($this->folder_name_to_id($mailbox));
        $methods = array(array(
            'Mailbox/set',
            array(
                'accountId' => $this->account_id,
                'destroy' => $ids
            ),
            'dm'
        ));
        $destroyed = $this->send_and_parse($methods, array(0, 1, 'destroyed'), array());
        $this->reset_folders();
        return $destroyed && count($destroyed) > 0;
    }

    public function rename_mailbox($mailbox, $new_mailbox) {
        $id = $this->folder_name_to_id($mailbox);
        list($name, $parent) = $this->split_name_and_parent($new_mailbox);
        $methods = array(array(
            'Mailbox/set',
            array(
                'accountId' => $this->account_id,
                'update' => array($id => array('parentId' => $parent, 'name' => $name))
            ),
            'rm'
        ));
        $updated = $this->send_and_parse($methods, array(0, 1, 'updated'), array());
        $this->reset_folders();
        return $updated && count($updated) > 0;
    }

    public function get_state() {
        return $this->state;
    }

    public function show_debug() {
        return array(
            'commands' => $this->requests,
            'responses' => $htis->responses
        );
    }

    public function get_first_message_part($uid, $type, $subtype=false, $struct=false) {
        if (!$subtype) {
            $flds = array('type' => $type);
        }
        else {
            $flds = array('type' => $type, 'subtype' => $subtype);
        }
        $matches = $this->search_bodystructure($struct, $flds, false);
        if (empty($matches)) {
            return array(false, false);
        }

        $subset = array_slice(array_keys($matches), 0, 1);
        $msg_part_num = $subset[0];
        return array($msg_part_num, $this->get_message_content($uid, $msg_part_num));
    }

    public function get_mailbox_page($mailbox, $sort, $rev, $filter, $offset=0, $limit=0, $keyword=false) {
        $mailbox_id = $this->folder_name_to_id($mailbox);
        $filter = array('inMailbox' => $mailbox_id);
        if ($keyword) {
            $filter['hasKeyword'] = $keyword;
        }
        $methods = array(
            array(
                'Email/query',
                array(
                    'accountId' => $this->account_id,
                    'filter' => $filter,
                    'sort' => array(array(
                        'property' => $this->sorts[$sort],
                        'isAscending' => $rev ? false : true
                    )),
                    'position' => $offset,
                    'limit' => $limit,
                    'calculateTotal' => true
                ),
                'gmp'
            )
        );
        $res = $this->send_command($this->api_url, $methods);
        $total = $this->search_response($res, array(0, 1, 'total'), 0);
        $msgs = $this->get_message_list($this->search_response($res, array(0, 1, 'ids'), array()));
        $this->setup_selected_mailbox($mailbox, $total);
        return array($total, $msgs);
    }

    public function get_folder_list_by_level($level=false) {
        if (!$level) {
            $level = '';
        }
        if (count($this->folder_list) == 0) {
            $this->reset_folders();
        }
        return $this->parse_folder_list_by_level($level);
    }

    public function dump_cache() {
        return array(
            $this->session,
            $this->folder_list
        );
    }

    public function load_cache($data) {
        $this->session = $data[0];
        $this->folder_list = $data[1];
    }

    public function connect($cfg) {
        $this->build_headers($cfg['username'], $cfg['password']);
        return $this->authenticate(
            $cfg['username'],
            $cfg['password'],
            $cfg['server'],
            $cfg['port']
        );
    }

    public function disconnect() {
        return true;
    }

    public function authenticate($username, $password, $url, $port) {
        if (is_array($this->session)) {
            $res = $this->session;
        }
        else {
            $auth_url = $this->prep_url($url, $port);
            $res = $this->send_command($auth_url, array(), 'GET');
        }
        if (is_array($res) &&
            array_key_exists('apiUrl', $res) &&
            array_key_exists('accounts', $res)) {

            $this->init_session($res, $url, $port);
            return true;
        }
        return false;
    }

    public function get_folder_list() {
        $methods = array(array(
            'Mailbox/get',
            array(
                'accountId' => $this->account_id,
                'ids' => NULL
            ),
            'fl'
        ));
        $res = $this->send_command($this->api_url, $methods);
        return $this->search_response($res, array(0, 1, 'list'), array());
    }

    public function get_special_use_mailboxes($type=false) {
    }

    public function get_namespaces() {
        return array(array(
            'class' => 'personal',
            'prefix' => false,
            'delim' => $this->delim
        ));
    }

    public function select_mailbox($mailbox) {
        $this->setup_selected_mailbox($mailbox, 0);
        return true;
    }

    public function get_message_list($uids) {
        $result = array();
        $body = array('size');
        $flds = array('receivedAt', 'sender', 'replyTo', 'sentAt',
            'hasAttachment', 'size', 'keywords', 'id', 'subject', 'from', 'to', 'messageId');
        $methods = array(array(
            'Email/get',
            array(
                'accountId' => $this->account_id,
                'ids' => $uids,
                'properties' => $flds,
                'bodyProperties' => $body
            ),
            'gml'
        ));
        foreach ($this->send_and_parse($methods, array(0, 1, 'list'), array()) as $msg) {
            $result[] = $this->normalize_headers($msg);
        }
        return $result;
    }

    public function get_message_structure($uid) {
        $struct = $this->get_raw_bodystructure($uid);
        $converted = $this->parse_bodystructure_response($struct);
        return $converted;
    }

    public function get_message_content($uid, $message_part, $max=false, $struct=false) {
        $methods = array(array(
            'Email/get',
            array(
                'accountId' => $this->account_id,
                'ids' => array($uid),
                'fetchAllBodyValues' => true,
                'properties' => array('bodyValues')
            ),
            'gmc'
        ));
        return $this->send_and_parse($methods, array(0, 1, 'list', 0, 'bodyValues', $message_part, 'value'));
    }

    public function search($target='ALL', $uids=false, $terms=array(), $esearch=array(), $exclude_deleted=true, $exclude_auto_bcc=true, $only_auto_bcc=false) {

        $mailbox_id = $this->folder_name_to_id($this->selected_mailbox['detail']['name']);
        $filter = array('inMailbox' => $mailbox_id);
        if ($target == 'UNSEEN') {
            $filter['notKeyword'] = '$seen';
        }
        elseif ($target == 'FLAGGED') {
            $filter['hasKeyword'] = '$flagged';
        }
        if ($converted_terms = $this->process_imap_search_terms($terms)) {
            $filter = $converted_terms + $filter;
        }
        $methods = array(
            array(
                'Email/query',
                array(
                    'accountId' => $this->account_id,
                    'filter' => $filter,
                ),
                's'
            )
        );
        return $this->send_and_parse($methods, array(0, 1, 'ids'), array());
    }

    public function get_message_headers($uid, $message_part=false, $raw=false) {
        $flds = array('receivedAt', 'sender', 'replyTo', 'sentAt',
            'hasAttachment', 'size', 'keywords', 'id', 'subject', 'from', 'to', 'messageId');
        $methods = array(array(
            'Email/get',
            array(
                'accountId' => $this->account_id,
                'ids' => array($uid),
                'properties' => $flds,
                'bodyProperties' => array()
            ),
            'gmh'
        ));
        $headers = $this->send_and_parse($methods, array(0, 1, 'list', 0), array());
        return $this->normalize_headers($headers);
    }

    public function message_action($action, $uids, $mailbox=false, $keyword=false) {
        $methods = array();
        $key =false;
        if (array_key_exists($action, $this->mod_map)) {
            $methods = $this->modify_msg_methods($action, $uids);
            $key = 'updated';
        }
        elseif ($action == 'DELETE') {
            $methods = $this->delete_msg_methods($uids);
            $key = 'destroyed';
        }
        elseif (in_array($action, array('MOVE', 'COPY'), true)) {
            $methods = $this->move_copy_methods($action, $uids, $mailbox);
            $key = 'updated';
        }
        if (!$key) {
            return false;
        }
        $changed_uids = array_keys($this->send_and_parse($methods, array(0, 1, $key), array()));
        return count($changed_uids) == count($uids);
    }

    public function search_bodystructure($struct, $search_flds, $all=true, $res=array()) {
        $this->struct_object = new Hm_IMAP_Struct(array(), $this);
        $res = $this->struct_object->recursive_search($struct, $search_flds, $all, $res);
        return $res;
    }

    /**
     * PRIVATE HELPER METHODS
     */

    private function move_copy_methods($action, $uids, $mailbox) {
        /* TODO: this assumes a message can only be in ONE mailbox, other refs will be lost,
                 we should switch to "patch" syntax to fix this */
        if ($action == 'MOVE') {
            $mailbox_ids = array('mailboxIds' => array($this->folder_name_to_id($mailbox) => true));
        }
        else {
            $mailbox_ids = array('mailboxIds' => array(
                $this->folder_name_to_id($this->selected_mailbox['detail']['name']) => true,
                $this->folder_name_to_id($mailbox) => true));
        }
        $keywords = array();
        foreach ($uids as $uid) {
            $keywords[$uid] = $mailbox_ids;
        }
        return array(array(
            'Email/set',
            array(
                'accountId' => $this->account_id,
                'update' => $keywords
            ),
            'ma'
        ));
    }

    private function modify_msg_methods($action, $uids) {
        $keywords = array();
        $jmap_keyword = $this->mod_map[$action];
        foreach ($uids as $uid) {
            $keywords[$uid] = array(sprintf('keywords/%s', $jmap_keyword[0]) => $jmap_keyword[1]);
        }
        return array(array(
            'Email/set',
            array(
                'accountId' => $this->account_id,
                'update' => $keywords
            ),
            'ma'
        ));
    }

    private function delete_msg_methods($uids) {
        return array(array(
            'Email/set',
            array(
                'accountId' => $this->account_id,
                'destroy' => $uids
            ),
            'ma'
        ));
    }

    private function get_raw_bodystructure($uid) {
        $methods = array(array(
            'Email/get',
            array(
                'accountId' => $this->account_id,
                'ids' => array($uid),
                'properties' => array('bodyStructure')
            ),
            'gbs'
        ));
        $res = $this->send_command($this->api_url, $methods);
        return $this->search_response($res, array(0, 1, 'list', 0, 'bodyStructure'), array());
    }

    private function parse_bodystructure_response($data) {
        $top = $this->translate_struct_keys($data);
        if (array_key_exists('subParts', $data)) {
            $top['subs'] = $this->parse_subs($data['subParts']);
        }
        if (array_key_exists('partId', $data)) {
            return array($data['partId'] => $top);
        }
        return array($top);
    }

    private function parse_subs($data) {
        $res = array();
        foreach ($data as $sub) {
            if ($sub['partId']) {
                $this->msg_part_id = $sub['partId'];
            }
            else {
                $sub['partId'] = $this->msg_part_id + 1;
            }
            $res[$sub['partId']] = $this->translate_struct_keys($sub);
            if (array_key_exists('subParts', $sub)) {
                $res[$sub['partId']]['subs'] = $this->parse_subs($sub['subParts']);
            }
        }
        return $res;
    }

    private function translate_struct_keys($part) {
        return array(
            'type' => explode('/', $part['type'])[0],
            'name' => $part['name'],
            'subtype' => explode('/', $part['type'])[1],
            'blob_id' => array_key_exists('blobId', $part) ? $part['blobId'] : false,
            'size' => $part['size'],
            'attributes' => array('charset' => $part['charset'])
        );
    }

    private function normalize_headers($msg) {
        return array(
            'uid' => $msg['id'],
            'flags' => $this->keywords_to_flags($msg['keywords']),
            'internal_date' => $msg['receivedAt'],
            'size' => $msg['size'],
            'date' => $msg['sentAt'],
            'from' => $this->combine_addresses($msg['from']),
            'to' => $this->combine_addresses($msg['to']),
            'subject' => $msg['subject'],
            'content-type' => '',
            'timestamp' => strtotime($msg['receivedAt']),
            'charset' => '',
            'x-priority' => '',
            'type' => 'jmap',
            'references' => '',
            'message_id' => implode(' ', $msg['messageId']),
            'x_auto_bcc' => ''
        );
    }

    private function init_session($data, $url, $port) {
        $this->state = 'authenticated';
        $this->session = $data;
        $this->api_url = sprintf(
            '%s:%s%s',
            preg_replace("/\/$/", '', $url),
            $port,
            $data['apiUrl']
        );
        $this->download_url = sprintf(
            '%s:%s%s',
            preg_replace("/\/$/", '', $url),
            $port,
            $data['downloadUrl']
        );
        $this->upload_url = sprintf(
            '%s:%s%s',
            preg_replace("/\/$/", '', $url),
            $port,
            $data['uploadUrl']
        );
        $this->account_id = array_keys($data['accounts'])[0];
        $this->reset_folders();
    }

    private function keywords_to_flags($keywords) {
        $flags = array();
        if (array_key_exists('$seen', $keywords) && $keywords['$seen']) {
            $flags[] = '\Seen';
        }
        if (array_key_exists('$flagged', $keywords) && $keywords['$flagged']) {
            $flags[] = '\Flagged';
        }
        if (array_key_exists('$answered', $keywords) && $keywords['$answered']) {
            $flags[] = '\Answered';
        }
        return implode(' ', $flags);
    }

    private function combine_addresses($addrs) {
        $res = array();
        foreach ($addrs as $addr) {
            $res[] = implode(' ', $addr);
        }
        return implode(', ', $res);
    }
    private function merge_headers($headers) {
        $req_headers = $this->headers;
        foreach ($headers as $index => $val) {
            $req_headers[$index] = $val;
        }
        return $req_headers;
    }

    private function send_command($url, $methods=array(), $method='POST', $post=array(), $headers=array()) {
        $body = '';
        if (count($methods) > 0) {
            $body = $this->format_request($methods);
        }
        $headers = $this->merge_headers($headers);
        $this->requests[] = array($url, $headers, $body, $method, $post);
        return $this->api->command($url, $headers, $post, $body, $method);
    }

    private function search_response($data, $key_path, $default=false) {
        array_unshift($key_path, 'methodResponses');
        foreach ($key_path as $key) {
            if (is_array($data) && array_key_exists($key, $data)) {
                $data = $data[$key];
            }
            else {
                return $default;
            }
        }
        return $data;
    }

    private function send_and_parse($methods, $key_path, $default=false, $method='POST', $post=array()) {
        $res = $this->send_command($this->api_url, $methods, $method, $post);
        $this->responses[] = $res;
        return $this->search_response($res, $key_path, $default);
    }

    private function format_request($methods, $caps=array()) {
        return json_encode(array(
            'using' => count($caps) == 0 ? $this->default_caps : $caps,
            'methodCalls' => $methods
        ));
    }

    private function build_headers($user, $pass) {
        $this->headers = array(
            'Authorization: Basic '. base64_encode(sprintf('%s:%s', $user, $pass)),
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Content-Type: application/json',
            'Accept: application/json'
        );
    }

    private function prep_url($url, $port) {
        $url = preg_replace("/\/$/", '', $url);
        if ($port == 80 || $port == 443) {
            return sprintf('%s/.well-known/jmap/', $url);
        }
        return sprintf('%s:%s/.well-known/jmap/', $url, $port);
    }

    private function setup_selected_mailbox($mailbox, $total) {
        $this->selected_mailbox = array('detail' => array(
            'selected' => 1,
            'name' => $mailbox,
            'exists' => $total
        ));
    }

    private function parse_folder_list_by_level($level) {
        $result = array();
        foreach ($this->folder_list as $name => $folder) {
            if ($folder['level'] == $level) {
                $result[$name] = $folder;
            }
        }
        return $result;
    }

    private function parse_imap_folders($data) {
        $lookup = array();
        foreach ($data as $vals) {
            $vals['children'] = false;
            $lookup[$vals['id']] = $vals;
        }
        foreach ($data as $vals) {
            if ($vals['parentId']) {
                $parents = $this->get_parent_recursive($vals, $lookup, $parents=array());
                $level = implode($this->delim, $parents);
                $parents[] = $vals['name'];
                $lookup[$vals['parentId']]['children'] = true;
            }
            else {
                $parents = array($vals['name']);
                $level = '';
            }
            $lookup[$vals['id']]['basename'] = $vals['name'];
            $lookup[$vals['id']]['level'] = $level;
            $lookup[$vals['id']]['name_parts'] = $parents;
            $lookup[$vals['id']]['name'] = implode($this->delim, $parents);
        }
        return $this->build_imap_folders($lookup);
    }

    private function build_imap_folders($data) {
        $result = array();
        foreach ($data as $vals) {
            if (strtolower($vals['name']) == 'inbox') {
                $vals['name'] = 'INBOX';
            }
            $result[$vals['name']] = array(
                    'delim' => $this->delim,
                    'level' => $vals['level'],
                    'basename' => $vals['basename'],
                    'children' => $vals['children'],
                    'name' => $vals['name'],
                    'type' => 'jmap',
                    'noselect' => false,
                    'id' => $vals['id'],
                    'name_parts' => $vals['name_parts']
                );
        }
        return $result;
    }

    private function get_parent_recursive($vals, $lookup, $parents) {
        $vals = $lookup[$vals['parentId']];
        $parents[] = $vals['name'];
        if ($vals['parentId']) {
            $parents = $this->get_parent_recursive($vals, $lookup, $parents);
        }
        return $parents;
    }

    private function folder_name_to_id($name) {
        if (count($this->folder_list) == 0 || !array_key_exists($name, $this->folder_list)) {
            $this->reset_folders();
        }
        if (array_key_exists($name, $this->folder_list)) {
            return $this->folder_list[$name]['id'];
        }
        return false;
    }

    private function reset_folders() {
        $this->folder_list = $this->parse_imap_folders($this->get_folder_list());
    }

    private function process_imap_search_terms($terms) {
        $converted_terms = array();
        $map = array(
            'SINCE' => 'after',
            'SUBJECT' => 'subject',
            'TO' => 'to',
            'FROM' => 'from',
            'BODY' => 'body',
            'TEXT' => 'text'
        );
        foreach ($terms as $vals) {
            if (array_key_exists($vals[0], $map)) {
                if ($vals[0] == 'SINCE') {
                    $vals[1] = gmdate("Y-m-d\TH:i:s\Z", strtotime($vals[1]));
                }
                $converted_terms[$map[$vals[0]]] = $vals[1];
            }
        }
        return count($converted_terms) > 0 ? $converted_terms : false;
    }

    private function split_name_and_parent($mailbox) {
        $parent = NULL;
        $parts = explode($this->delim, $mailbox);
        $name = array_pop($parts);
        if (count($parts) > 0) {
            $parent = $this->folder_name_to_id(implode($this->delim, $parts));
        }
        return array($name, $parent);
    }
}