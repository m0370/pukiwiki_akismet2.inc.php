<?php
/**
 *  Akismet2 Spamfilter Plugin (Akismet + Cloudflare Turnstile)
 *
 *  @orinigal author     sonots
 *  @license    https://www.gnu.org/licenses/gpl.html GPL v2
 *  @link       https://github.com/m0370/pukiwiki_akismet.inc.php
 *  @version    $Id: akismet2.inc.php, v1.0.0 2026-06-13 m0370
 *  @package    plugin
 *  @note       akismet.inc.php の人間判定パート（reCAPTCHA v2）を
 *              Cloudflare Turnstile に置換した版。旧版と共存可能。
 */

// Initial settings
if (! defined('PLUGIN_AKISMET2_API_KEY')) {
    define('PLUGIN_AKISMET2_API_KEY', ''); // insert AKISMET API key
}
if (! defined('PLUGIN_AKISMET2_TURNSTILE_SITE_KEY')) {
    define('PLUGIN_AKISMET2_TURNSTILE_SITE_KEY', ''); // insert Cloudflare Turnstile site key
}
if (! defined('PLUGIN_AKISMET2_TURNSTILE_SECRET_KEY')) {
    define('PLUGIN_AKISMET2_TURNSTILE_SECRET_KEY', ''); // insert Cloudflare Turnstile secret key
}

// log settings
if (! defined('PLUGIN_AKISMET2_SPAMLOG_FILENAME')) {
    define('PLUGIN_AKISMET2_SPAMLOG_FILENAME', 
           (defined('LOG_DIR') ? LOG_DIR : CACHE_DIR) . 'spamlog.txt'); // LOG_DIR (Plus!)
}
if (! defined('PLUGIN_AKISMET2_SPAMLOG_DETAIL')) {
    define('PLUGIN_AKISMET2_SPAMLOG_DETAIL', FALSE);
}
if (! defined('PLUGIN_AKISMET2_ONELOG_DAYS')) {
    define('PLUGIN_AKISMET2_ONELOG_DAYS', 10);
}
if (! defined('PLUGIN_AKISMET2_KEEPLOG')) {
    define('PLUGIN_AKISMET2_KEEPLOG', 3);
}
if (! isset($GLOBALS['PLUGIN_AKISMET2_TABLE_ORDER'])) {
    $GLOBALS['PLUGIN_AKISMET2_TABLE_ORDER'] =  array('time', 'cmd', 'page', 'ip', 'host', 'agent', 'body');
}

// Set FALSE to use turnstile without akismet (no log will be taken with FALSE)
if (! defined('PLUGIN_AKISMET2_USE_AKISMET')) {
    define('PLUGIN_AKISMET2_USE_AKISMET', TRUE);
}
// Set FALSE to use akismet without turnstile
if (! defined('PLUGIN_AKISMET2_USE_TURNSTILE')) {
    define('PLUGIN_AKISMET2_USE_TURNSTILE', TRUE);
}
// Do not spam filter POST via these plugins (SPAM filter works only for POST, not GET)
if (! defined('PLUGIN_AKISMET2_IGNORE_PLUGINS')) {
    define('PLUGIN_AKISMET2_IGNORE_PLUGINS', 'read,vote,vote2,timestamp');
}

// Do not require to captcha if the user is known as human
if (! defined('PLUGIN_AKISMET2_THROUGH_IF_ADMIN')) { // Plus!
    define('PLUGIN_AKISMET2_THROUGH_IF_ADMIN', FALSE);
}
if (! defined('PLUGIN_AKISMET2_THROUGH_IF_ENROLLEE')) { // Plus!
    define('PLUGIN_AKISMET2_THROUGH_IF_ENROLLEE', FALSE);
}
if (! defined('PLUGIN_AKISMET2_USE_SESSION')) {
    define('PLUGIN_AKISMET2_USE_SESSION', TRUE);
}

// Debug
if (! defined('PLUGIN_AKISMET2_AUTOPOST_AFTER_SUBMITHAM')) {
    define('PLUGIN_AKISMET2_AUTOPOST_AFTER_SUBMITHAM', TRUE);
}
if (! defined('PLUGIN_AKISMET2_CAPTCHA_LOG')) {
    define('PLUGIN_AKISMET2_CAPTCHA_LOG', FALSE);
}
// Reverse DNS lookup on spamlog (may slow down under spam flood, default FALSE)
if (! defined('PLUGIN_AKISMET2_LOG_REVERSE_DNS')) {
    define('PLUGIN_AKISMET2_LOG_REVERSE_DNS', FALSE);
}
// Restrict spamlog view (?cmd=akismet2) to admin (logs contain visitor IPs/UAs)
if (! defined('PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY')) {
    define('PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY', TRUE);
}

class PluginAkismet2
{
    function action()
    {
        global $vars;
        if (isset($vars['submitHam'])) {
            return $this->submitham_action();
        } else {
            // spam logs contain visitor IPs/UAs, so restrict to admin by default
            if (PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY && ! is_admin(NULL, PLUGIN_AKISMET2_USE_SESSION, TRUE)) {
                die_message('akismet2 : スパムログの閲覧は管理者のみ許可されています (PLUGIN_AKISMET2_SPAMLOG_ADMIN_ONLY)。');
            }
            // only the spamlog and its rotated copies may be specified
            $logfile = isset($vars['logfile']) ? PluginAkismet2::resolve_logfile($vars['logfile']) : PLUGIN_AKISMET2_SPAMLOG_FILENAME;
            $body = $this->show_logfile_listbox($logfile);
            $body .= $this->show_spamlog($logfile);
            return array('msg'=>_('Spam Log'), 'body'=>$body);
        }
    }

    // static
    // whitelist for the logfile request parameter
    // (an arbitrary path would allow reading any file on the server)
    static function resolve_logfile($logfile)
    {
        $allowed = array(PLUGIN_AKISMET2_SPAMLOG_FILENAME);
        for ($i = 1; $i <= PLUGIN_AKISMET2_KEEPLOG; $i++) {
            $allowed[] = PLUGIN_AKISMET2_SPAMLOG_FILENAME . '.' . $i;
        }
        return in_array($logfile, $allowed, true) ? $logfile : PLUGIN_AKISMET2_SPAMLOG_FILENAME;
    }
    
    function show_logfile_listbox($current = PLUGIN_AKISMET2_SPAMLOG_FILENAME)
    {
        $form = '<form action="' . get_script_uri() . '?cmd=akismet2" method="post">';
        $form .= '<div>' . "\n";
        $form .= ' <input type="hidden" name="pcmd" value="spamlog" />' . "\n";
        $form .= ' <select name="logfile">' . "\n";
        
        $logfile = PLUGIN_AKISMET2_SPAMLOG_FILENAME;
        $form .= '  <option value="' . $logfile . '"' . 
            ($current == $logfile ? ' selected="selected"' : '') .
            '>' . basename($logfile) . '</option>' . "\n";
        
        for ($i = 1; $i <= PLUGIN_AKISMET2_KEEPLOG; $i++) {
            $logfile = htmlspecialchars(PLUGIN_AKISMET2_SPAMLOG_FILENAME . '.' . $i);
            $form .= '  <option value="' . $logfile . '"' . 
                ($current == $logfile ? ' selected="selected"' : '') .
                '>' . basename($logfile) . '</option>' . "\n";
        }
        
        $form .= ' </select>' . "\n";
        $form .= ' <input type="submit" name="submit" value="Submit" />' . "\n";
        $form .= '</div>' . "\n";
        $form .= '</form>' . "\n";
        return $form;
    }

    function show_spamlog($logfile = PLUGIN_AKISMET2_SPAMLOG_FILENAME)
    {
        $labels = array(
             'time'    => _('Time'), 
             'ip'      => _('IP'), 
             'host'    => _('Host'), 
             'agent'   => _('User Agent'),
             'page'    => _('Page'),
             'cmd'     => _('Cmd'), 
             'body'    => _('Body'), 
        );
        $sort_types = array(
             'time'    => 'String', 
             'ip'      => 'String', 
             'host'    => 'String', 
             'agent'   => 'String',
             'page'    => 'String', 
             'cmd'     => 'String', 
             'body'    => 'String', 
        );
        $table_id = 'akismet2_spamlog';
        $ret = '';

        if (($lines = @file($logfile)) === FALSE) {
            $ret = '<div>The log file, ' . htmlspecialchars($logfile) . ' , does not exist.</div>';
            return $ret;
        }
        $logdate = rtrim(array_shift($lines));
        //if ($logdate != '') {
        //$ret .= '<h2>' . htmlspecialchars($logdate) . '</h2>' . "\n";
        //}

        $ret .= '<div class="ie5"><table id="' . $table_id . '" class="style_table" cellspacing="1" border="0">' . "\n";
        $ret .= '<thead>' . "\n";
        $ret .= ' <tr>';
        foreach ($GLOBALS['PLUGIN_AKISMET2_TABLE_ORDER'] as $key) {
            $ret .= '<td class="style_td">' . $labels[$key] . '</td>';
        }
        $ret .= '</tr>' . "\n";
        $ret .= '</thead>' . "\n";

        $ret .= '<tbody>' . "\n";
        foreach ($lines as $line) {
            $line = rtrim($line);
            $logdata = unserialize($line);
            $logdata['body'] = str_replace('<br />', "\n", $logdata['body']);
            $ret .= ' <tr>';
            foreach ($GLOBALS['PLUGIN_AKISMET2_TABLE_ORDER'] as $key) {
                $ret .= '<td class="style_td">' . htmlspecialchars($logdata[$key]) . '</td>'; 
            }
            $ret .= '</tr>' . "\n";
        }
        $ret .= '</tbody>' . "\n";
        $ret .= '</table></div>' . "\n";

        // sortabletable.js
        $sorts = array();
        foreach ($GLOBALS['PLUGIN_AKISMET2_TABLE_ORDER'] as $key) {
            $sorts[] = $sort_types[$key];
        }
        $ret .= '<script type="text/javascript">' . "\n";
        $ret .= '<!-- <![CDATA[' . "\n";
        $ret .= 'var st = new SortableTable(document.getElementById("' . $table_id . '"),["' . implode('","',$sorts) . '"]);' . "\n";
        $ret .= '//]]>-->' . "\n";
        $ret .= '</script>' . "\n";
        return $ret;
    }
    
    function submitham_action()
    {
        global $vars, $post, $get;

        $error = NULL;
        $captcha_valid = TRUE;
        if (PLUGIN_AKISMET2_USE_TURNSTILE) {
            // Cloudflare Turnstile verification
            // Unlike the reCAPTCHA version, a missing token is NOT valid:
            // the captcha form is shown again, so the input is kept
            $token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
            $error_codes = array();
            $captcha_valid = PluginAkismet2::turnstile_verify($token, $error_codes);
            if (! $captcha_valid && PLUGIN_AKISMET2_CAPTCHA_LOG) {
                PluginAkismet2::spamlog_write($vars, array('body'=>'turnstile failed: ' . implode(',', $error_codes)), (defined('LOG_DIR') ? LOG_DIR : CACHE_DIR) . 'captchalog.txt');
            }
        }
        // Memorize the originally called plugin saved by get_captcha_form (v2.1.0)
        $orig_cmd = isset($vars['akismet2_orig_cmd']) ? $vars['akismet2_orig_cmd'] : '';
        // direct access or a broken POST may lack these structures
        $comment = (isset($vars['comment']) && is_array($vars['comment'])) ? $vars['comment'] : array();
        $vars    = (isset($vars['vars']) && is_array($vars['vars'])) ? $vars['vars'] : array();
        if ($captcha_valid) {
            if (PLUGIN_AKISMET2_CAPTCHA_LOG) PluginAkismet2::spamlog_write($vars, array('body'=>'break'), (defined('LOG_DIR') ? LOG_DIR : CACHE_DIR) . 'captchalog.txt');

            // Memorize the user is human because he could pass captcha
            $use_authlevel = PLUGIN_AKISMET2_THROUGH_IF_ENROLLEE ? ROLE_AUTH :
                (PLUGIN_AKISMET2_THROUGH_IF_ADMIN ? ROLE_ADM_CONTENTS : 0);
            is_human(TRUE, PLUGIN_AKISMET2_USE_SESSION, $use_authlevel); // set to session

            // submitHam
            if (PLUGIN_AKISMET2_USE_AKISMET) {
                $akismet = new Akismet(get_script_uri(), PLUGIN_AKISMET2_API_KEY, $comment);
                $akismet->submitHam();
            }

            // autopost
            if (PLUGIN_AKISMET2_AUTOPOST_AFTER_SUBMITHAM) {
                // throw to originally called plugin
                // refer lib/pukiwiki.php
                // v2.1.0: prefer the cmd saved before showing the captcha form.
                // Never fall back to 'read': it silently discards the posted content.
                $cmd = $orig_cmd !== '' ? $orig_cmd :
                    (isset($vars['cmd']) ? $vars['cmd'] : (isset($vars['plugin']) ? $vars['plugin'] : ''));
                if (($cmd === '' || $cmd === 'read') && isset($vars['msg']) && isset($vars['page'])) {
                    $cmd = 'edit'; // edit posts may lack cmd/plugin in saved vars
                }
                if ($cmd !== '' && $cmd !== 'read' && exist_plugin_action($cmd)) {
                    $post = $vars;
                    $get = array();
                    do_plugin_init($cmd);
                    return do_plugin_action($cmd);
                } else {
                    // could not determine the original plugin: never lose the input
                    return array('msg'=>'キャプチャ認証', 'body'=>PluginAkismet2::recover_form($vars));
                }
            } else {
                $body = '<p>スパム取り消し報告を行いました。以下がスパムと判断された投稿内容です。再度投稿してください。</p>' . "\n";
                $body .= '<div class="ie5"><table class="style_table" cellspacing="1" border="0"><tbody>' . "\n";
                foreach ($vars as $key => $val) {
                    $body .= '<tr>' . "\n";
                    $body .= ' <td class="style_td">' . htmlspecialchars($key) . '<td>' . "\n";
                    $body .= ' <td class="style_td">' . htmlspecialchars($val) . '<td>' . "\n";
                    $body .= '</tr>' . "\n";
                }
                $body .= '</tbody></table></div>' . "\n";
                return array('msg'=>'キャプチャ認証', 'body'=>$body);
            }
        } else {
            $form = PluginAkismet2::get_captcha_form($vars, $comment, $error);
            return array('msg'=>'キャプチャ認証', 'body'=>$form);
        }
    }

    // obsolete: should not be used
    function write_before()
    {
        global $vars;
        $args        = func_get_args();
        $page        = &$args[0];
        $postdata    = &$args[1];
        $notimestamp = &$args[2];
        $oldpostdata = &$args[3];
        $optargs     = &$args[4];

        $postlines = explode("\n", $postdata);
        $oldlines  = explode("\n", $oldpostdata);
        $difflines = array_diff($postlines, $oldlines);
        $body      = implode("\n", $difflines);
        $comment = array(
             'author'       => '',
             'email'        => '',
             'website'      => '',
             'body'         => $body,
             'permalink'    => '',
             'user_ip'      => $_SERVER['REMOTE_ADDR'],
             'user_agent'   => $_SERVER['HTTP_USER_AGENT'],
        );
        return PluginAkismet2::spamfilter($comment);
    }

    // static
    static function spamfilter($comment = null)
    {
        global $vars, $defaultpage;
        // Through if GET (Check only POST)
        if ($_SERVER['REQUEST_METHOD'] === 'GET') return;
        // Through if POST is from akismet plugin (submitHam)
        if (isset($vars['cmd']) && $vars['cmd'] == 'akismet2') return;
        // Through if in IGNORE list
        $cmd = isset($vars['cmd']) ? $vars['cmd'] : (isset($vars['plugin']) ? $vars['plugin'] : 'read');
        if (defined('PLUGIN_AKISMET2_IGNORE_PLUGINS')) {
            if (in_array($cmd, explode(',', PLUGIN_AKISMET2_IGNORE_PLUGINS))) return;
        }

        // Through if already known he is a human
        $use_authlevel = PLUGIN_AKISMET2_THROUGH_IF_ENROLLEE ? ROLE_AUTH :
            (PLUGIN_AKISMET2_THROUGH_IF_ADMIN ? ROLE_ADM_CONTENTS : 0);
        if (is_human(NULL, PLUGIN_AKISMET2_USE_SESSION, $use_authlevel)) return;

        // Initialize $comment
        if (! isset($comment)) {
            // special case (now only supports edit plugin)
            if ((isset($vars['cmd']) && $vars['cmd'] === 'edit') || (isset($vars['plugin']) && $vars['plugin'] === 'edit')) {
                $body = $vars['msg'];
            } else {
                $body = implode("\n", $vars);
            }
            $comment = array(
                'author'       => '',
                'email'        => '',
                'website'      => '',
                'body'         => $body,
                'permalink'    => '',
                'user_ip'      => $_SERVER['REMOTE_ADDR'],
                'user_agent'   => $_SERVER['HTTP_USER_AGENT'],
            );
        }

        $is_spam = TRUE;
        if (PLUGIN_AKISMET2_USE_AKISMET) {
            // Through if no body (Akismet recognizes as a spam if no body)
            if ($comment['body'] == '') return;

            // instantiate an instance of the class
            $akismet = new Akismet(get_script_uri(), PLUGIN_AKISMET2_API_KEY, $comment);
            // test for errors
            if($akismet->errorsExist()) { // returns TRUE if any errors exist
                // Compare with the integer constants (string keys never matched).
                // Check connection errors first: when akismet.com is unreachable,
                // _isValidApiKey also raises AKISMET_INVALID_KEY, but that must not
                // block postings (through if akismet.com is not available).
                if($akismet->isError(AKISMET_SERVER_NOT_FOUND)) {
                    //die_message('akismet2 : サーバへの接続に失敗しました.');
                } elseif($akismet->isError(AKISMET_RESPONSE_FAILED)) {
                    //die_message('akismet2 : レスポンスの取得に失敗しました');
                } elseif($akismet->isError(AKISMET_INVALID_KEY)) {
                    die_message('akismet2 : APIキーが不正です.');
                }
                $is_spam = FALSE; // through if akismet.com is not available.
            } else {
                $is_spam = $akismet->isSpam();
            }

            if ($is_spam) {
                $detail = PLUGIN_AKISMET2_SPAMLOG_DETAIL ? $comment : array();
                PluginAkismet2::spamlog_write($vars, $detail, PLUGIN_AKISMET2_SPAMLOG_FILENAME);
            }
        }
        if ($is_spam) {
            if (PLUGIN_AKISMET2_CAPTCHA_LOG) PluginAkismet2::spamlog_write($vars, array('body'=>'hit'), (defined('LOG_DIR') ? LOG_DIR : CACHE_DIR) . 'captchalog.txt');
            $form = PluginAkismet2::get_captcha_form($vars, $comment);
            // die_message('</strong>' . $form . '<strong>');
            $title = $page = 'キャプチャ認証';
            pkwk_common_headers();
            catbody($title, $page, $form);
            exit;
        }
    }

    // static
    static function get_captcha_form(&$vars, &$comment, $error = null)
    {
        $form = '';
        $form .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><!-- Cloudflare Turnstile -->' . "\n";
        $form .= '<form action="' . get_script_uri() . '" method="post">' . "\n";
        $form .= '<div>' . "\n";
        $form .= ' 認証を行います。' . "\n";
        if (PLUGIN_AKISMET2_USE_TURNSTILE) {
            if (PLUGIN_AKISMET2_TURNSTILE_SITE_KEY === '') {
                $form .= '<p>Turnstile site key が設定されていません。akismet2.inc.php 内の PLUGIN_AKISMET2_TURNSTILE_SITE_KEY を設定してください。</p>';
            } else {
                $form .= '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars(PLUGIN_AKISMET2_TURNSTILE_SITE_KEY) . '"></div>' . "\n";
            }
        } else {
            if (isset($error)) {
                $form .= '<p>';
                $form .= 'Turnstile error: ' . htmlspecialchars($error);
                $form .= '</p>';
            }
        }
        foreach ($comment as $key => $val) {
            $form .= ' <input type="hidden" name="comment[' . htmlspecialchars($key) . ']" value="' . htmlspecialchars($val) . '" />' . "\n";
        }
        foreach ($vars as $key => $val) {
            $form .= ' <input type="hidden" name="vars[' . htmlspecialchars($key) . ']" value="' . htmlspecialchars($val) . '" />' . "\n";
        }
        // v2.1.0: save the originally called plugin so that autopost never loses it
        $orig_cmd = isset($vars['cmd']) ? $vars['cmd'] : (isset($vars['plugin']) ? $vars['plugin'] : '');
        $form .= ' <input type="hidden" name="akismet2_orig_cmd" value="' . htmlspecialchars($orig_cmd) . '" />' . "\n";
        $form .= ' <input type="hidden" name="cmd" value="akismet2">' . "\n";
        $form .= ' <input type="submit" name="submitHam" value="GO" /><br />' . "\n";
        $form .= '</div>' . "\n";
        $form .= '</form>' . "\n";
        return $form;
    }

    // static
    // Cloudflare Turnstile server-side verification (siteverify)
    // returns TRUE/FALSE; $error_codes receives Cloudflare error-codes on failure
    static function turnstile_verify($token, &$error_codes = array())
    {
        $error_codes = array();
        if ($token === '') {
            $error_codes[] = 'missing-input-response';
            return FALSE;
        }
        if (! function_exists('curl_init')) {
            die_message('akismet2 : PHP の curl 拡張が必要です (Turnstile の検証に使用します)。');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'secret'   => PLUGIN_AKISMET2_TURNSTILE_SECRET_KEY,
            'response' => $token,
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        )));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        if ($result === FALSE) {
            $error_codes[] = 'connection-failed';
            return FALSE;
        }
        $json = json_decode($result, true);
        if (empty($json['success'])) {
            $error_codes = isset($json['error-codes']) ? $json['error-codes'] : array('unknown-error');
            return FALSE;
        }
        return TRUE;
    }

    // static
    // v2.1.0: present all the posted input as a re-submittable form
    // so that the user's writing is never lost even if autopost fails
    static function recover_form($vars)
    {
        $form = '<p>認証は完了しましたが、投稿先の自動特定に失敗したため自動投稿できませんでした。お手数ですが、下の「再投稿」ボタンを押して投稿を完了してください。</p>' . "\n";
        $form .= '<form action="' . get_script_uri() . '" method="post">' . "\n";
        $form .= '<div>' . "\n";
        foreach ($vars as $key => $val) {
            if ($key === 'msg') continue;
            $form .= ' <input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($val) . '" />' . "\n";
        }
        if (isset($vars['msg'])) {
            $form .= ' <textarea name="msg" rows="10" cols="80">' . htmlspecialchars($vars['msg']) . '</textarea><br />' . "\n";
        }
        $form .= ' <input type="submit" value="再投稿" />' . "\n";
        $form .= '</div>' . "\n";
        $form .= '</form>' . "\n";
        return $form;
    }

    // static
    static function spamlog_write($vars, $comment = array(), $filename = '')
    {
        global $defaultpage;
        if ($filename === '') $filename = PLUGIN_AKISMET2_SPAMLOG_FILENAME;

        $page = isset($vars['refer']) ? $vars['refer'] :
            (isset($vars['page']) ? $vars['page'] : (isset($defaultpage) ? $defaultpage : ''));
        $cmd  = isset($vars['cmd']) ? $vars['cmd'] : '';

        // logdata format
        $logdata = array();
        $logdata['time']  = date('y/m/d H:i:s');
        $logdata['ip']    = $_SERVER['REMOTE_ADDR'];
        // v2.1.0: reverse DNS lookup is opt-in (gethostbyaddr can stall under spam flood)
        $logdata['host']  = isset($_SERVER['REMOTE_HOST']) ? $_SERVER['REMOTE_HOST'] :
            (PLUGIN_AKISMET2_LOG_REVERSE_DNS ? gethostbyaddr($_SERVER['REMOTE_ADDR']) : $_SERVER['REMOTE_ADDR']);
        $logdata['agent'] = $_SERVER['HTTP_USER_AGENT'];
        $logdata['page']  = $page;
        $logdata['cmd']   = $cmd;
        $logdata['body'] =  isset($comment['body']) ? str_replace("\n", '<br />', $comment['body']) : '';
        $line = serialize($logdata) . "\n";

        $date = (int)(time() / 3600 / 24);
        // use localtime simply because time handling ways in pukiwiki plus! and official are different. 
        if (file_exists($filename)) {
            $logdate = rtrim(array_shift(file_head($filename, 1)));
            if ($date - PLUGIN_AKISMET2_ONELOG_DAYS >= $logdate) {
                slide_rename($filename, PLUGIN_AKISMET2_KEEPLOG, '.%d');
                @move($filename, $filename . '.1');
                file_put_contents($filename, $date . "\n");
            }
        } else {
            file_put_contents($filename, $date . "\n");
        }
        return file_put_contents($filename, $line, FILE_APPEND);
    }
}

/////// PukiWiki API Extension //////////////
if (! function_exists('is_human')) {
    /**
     * Human recognition using PukiWiki Auth methods
     *
     * @param boolean $is_human Tell this is a human (Use TRUE to store into session)
     * @param boolean $use_session Use Session log
     * @param int $use_rolelevel accepts users whose role levels are stronger than this
     * @return boolean
     */
    if (! defined('ROLE_AUTH')) define('ROLE_AUTH', 5); // define for PukiWiki Official
    if (! defined('ROLE_ENROLLEE')) define('ROLE_ENROLLEE', 4);
    if (! defined('ROLE_ADM_CONTENTS')) define('ROLE_ADM_CONTENTS', 3);
    if (! defined('ROLE_ADM')) define('ROLE_ADM', 2);
    if (! defined('ROLE_GUEST')) define('ROLE_GUEST', 0);
    function is_human($is_human = FALSE, $use_session = FALSE, $use_rolelevel = 0)
    {
        if (! $is_human) {
            if ($use_session) {
                if (session_status() === PHP_SESSION_NONE) session_start();
                $is_human = isset($_SESSION['pkwk_is_human']) && $_SESSION['pkwk_is_human'];
            }
        }
        if (! $is_human) {
            if (ROLE_GUEST < $use_rolelevel && $use_rolelevel <= ROLE_AUTH) {
                if (is_callable(array('auth', 'check_role'))) { // Plus!
                    $is_human = ! auth::check_role('role_auth');
                } else { // PukiWiki Official
                    $is_human = isset($_SERVER['PHP_AUTH_USER']);
                }
            }
        }
        if (! $is_human) {
            if (ROLE_GUEST < $use_rolelevel && $use_rolelevel <= ROLE_ADM_CONTENTS) {
                $is_human = is_admin(NULL, $use_session, TRUE);
                // In PukiWiki Official, username 'admin' is the Admin
            }
        }
        if ($use_session) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['pkwk_is_human'] = $is_human;
        } else {
            global $vars;
            $vars['pkwk_is_human'] = $is_human;
        }
        return $is_human;
    }
}
if (! function_exists('is_admin')) {
    /**
     * PukiWiki admin login with session
     *
     * @param string $pass Password. Use NULL when to get current session state. 
     * @param boolean $use_session Use Session log
     * @param boolean $use_authlog Use Auth log. 
     *  Username 'admin' is deemed to be Admin in PukiWiki Official. 
     *  PukiWiki Plus! has role management, roles ROLE_ADM and ROLE_ADM_CONTENTS are deemed to be Admin. 
     * @return boolean
     */
    function is_admin($pass = NULL, $use_session = FALSE, $use_authlog = FALSE)
    {
        $is_admin = FALSE;
        if (! $is_admin) {
            if ($use_session) {
                if (session_status() === PHP_SESSION_NONE) session_start();
                $is_admin = isset($_SESSION['pkwk_is_admin']) && $_SESSION['pkwk_is_admin'];
            }
        }
        // BasicAuth (etc) login
        if (! $is_admin) {
            if ($use_authlog) {
                if (is_callable(array('auth', 'check_role'))) { // Plus!
                    $is_admin = ! auth::check_role('role_adm_contents');
                } else {
                    $is_admin = (isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] === 'admin');
                }
            }
        }
        // PukiWiki Admin login
        if (! $is_admin) {
            if (isset($pass)) {
                $is_admin = function_exists('pkwk_login') ? pkwk_login($pass) : 
                    md5($pass) === $GLOBALS['adminpass']; // 1.4.3
            }
        }
        if ($use_session) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            if ($is_admin) $_SESSION['pkwk_is_admin'] = TRUE;
        } else {
            global $vars;
            $vars['pkwk_is_admin'] = $is_admin;
        }
        return $is_admin;
    }
}

/////////////// PHP Extesnion ///////////////
if (! function_exists('slide_rename')) {
    function slide_rename($basename, $max, $extfmt = '.%d') {
        for ($i = $max - 1; $i >= 1; $i--) {
            if (file_exists($basename . sprintf($extfmt, $i))) {
                $max = $i;
                break;
            }
        }
        for ($i = $max; $i >= 1; $i--) {
            @move($basename . sprintf($extfmt, $i), $basename . sprintf($extfmt, $i+1));
        }
    }
}
if (! function_exists('move')) {
    /**
     * Move a file (rename does not overwrite if $newname exists on Win)
     *
     * @param string $oldname
     * @param string $newname
     * @return boolean
     */
    function move($oldname, $newname) {
        if (! rename($oldname, $newname)) {
            if (copy ($oldname, $newname)) {
                unlink($oldname);
                return TRUE;
            }
            return FALSE;
        }
        return TRUE;
    }
}
if (! function_exists('file_put_contents')) {
    /**
     * Write a string to a file (PHP5 has this function)
     *
     * @param string $filename
     * @param string $data
     * @param int $flags
     * @return int the amount of bytes that were written to the file, or FALSE if failure
     */
    if (! defined('FILE_APPEND')) define('FILE_APPEND', 8);
    if (! defined('FILE_USE_INCLUDE_PATH')) define('FILE_USE_INCLUDE_PATH', 1);
    function file_put_contents($filename, $data, $flags = 0)
    {
        $mode = ($flags & FILE_APPEND) ? 'a' : 'w';
        $fp = fopen($filename, $mode);
        if ($fp === FALSE) {
            return FALSE;
        }
        if (is_array($data)) $data = implode('', $data);
        if ($flags & LOCK_EX) flock($fp, LOCK_EX);
        $bytes = fwrite($fp, $data);
        if ($flags & LOCK_EX) flock($fp, LOCK_UN);
        fclose($fp);
        return $bytes;
    }
}
if (! function_exists('_')) {
    function &_($str)
    {
        return $str;
    }
}

function plugin_akismet2_init()
{
    global $plugin_akismet2_name;
    if (class_exists('PluginAkismet2UnitTest')) {
        $plugin_akismet2_name = 'PluginAkismet2UnitTest';
    } elseif (class_exists('PluginAkismet2User')) {
        $plugin_akismet2_name = 'PluginAkismet2User';
    } else {
        $plugin_akismet2_name = 'PluginAkismet2';
    }
}

function plugin_akismet2_action()
{
    global $plugin_akismet2, $plugin_akismet2_name;
    $plugin_akismet2 = new $plugin_akismet2_name();
    return call_user_func(array(&$plugin_akismet2, 'action'));
}

function plugin_akismet2_write_before()
{
    global $plugin_akismet2_name; 
    $plugin_akismet2 = new $plugin_akismet2_name();
    $args = func_get_args();
    return call_user_func_array(array(&$plugin_akismet2, 'write_before'), $args);
}

//////// akismet.class.php //////////////////////////
/**
 * 01.26.2006 12:29:28est
 * 
 * Akismet PHP4 class
 * 
 * <b>Usage</b>
 * <code>
 *    $comment = array(
 *           'author'    => 'viagra-test-123',
 *           'email'     => 'test@example.com',
 *           'website'   => 'https://www.example.com/',
 *           'body'      => 'This is a test comment',
 *           'permalink' => 'https://yourdomain.com/yourblogpost.url',
 *        );
 *
 *    $akismet = new Akismet('https://www.yourdomain.com/', 'YOUR_WORDPRESS_API_KEY', $comment);
 *
 *    if($akismet->isError()) {
 *        echo"Couldn't connected to Akismet server!";
 *    } else {
 *        if($akismet->isSpam()) {
 *            echo"Spam detected";
 *        } else {
 *            echo"yay, no spam!";
 *        }
 *    }
 * </code>
 * 
 * @author Bret Kuhns {@link www.miphp.net}
 * @link https://www.miphp.net/blog/view/php4_akismet_class/
 * @version 0.3.3
 * @license https://www.opensource.org/licenses/mit-license.php MIT License
 */



// Error constants
// (guarded so that akismet.inc.php and akismet2.inc.php can coexist)
if (! defined("AKISMET_SERVER_NOT_FOUND")) define("AKISMET_SERVER_NOT_FOUND",    0);
if (! defined("AKISMET_RESPONSE_FAILED"))  define("AKISMET_RESPONSE_FAILED",    1);
if (! defined("AKISMET_INVALID_KEY"))      define("AKISMET_INVALID_KEY",        2);



// Guard for coexistence with akismet.inc.php which declares the same classes
if (! class_exists('AkismetObject')) {

// Base class to assist in error handling between Akismet classes
class AkismetObject {
    var $errors = array();
    
    
    /**
     * Add a new error to the errors array in the object
     *
     * @param    String    $name    A name (array key) for the error
     * @param    String    $string    The error message
     * @return void
     */ 
    // Set an error in the object
    function setError($name, $message) {
        $this->errors[$name] = $message;
    }
    

    /**
     * Return a specific error message from the errors array
     *
     * @param    String    $name    The name of the error you want
     * @return mixed    Returns a String if the error exists, a false boolean if it does not exist
     */
    function getError($name) {
        if($this->isError($name)) {
            return $this->errors[$name];
        } else {
            return false;
        }
    }
    
    
    /**
     * Return all errors in the object
     *
     * @return String[]
     */ 
    function getErrors() {
        return (array)$this->errors;
    }
    
    
    /**
     * Check if a certain error exists
     *
     * @param    String    $name    The name of the error you want
     * @return boolean
     */ 
    function isError($name) {
        return isset($this->errors[$name]);
    }
    
    
    /**
     * Check if any errors exist
     *
     * @return boolean
     */
    function errorsExist() {
        return (count($this->errors) > 0);
    }
    
    
}





// Used by the Akismet class to communicate with the Akismet service
class AkismetHttpClient extends AkismetObject {
    var $akismetVersion = '1.1';
    var $con;
    var $host;
    var $port;
    var $apiKey;
    var $blogUrl;
    var $errors = array();
    
    
    // Constructor
    // Akismet API is called over HTTPS (port 443) by default
    function __construct($host, $blogUrl, $apiKey, $port = 443) {
        $this->host = $host;
        $this->port = $port;
        $this->blogUrl = $blogUrl;
        $this->apiKey = $apiKey;
    }
    
    
    // Use the connection active in $con to get a response from the server and return that response
    function getResponse($request, $path, $type = "post", $responseLength = 1160) {
        $this->_connect();
        
        if($this->con && !$this->isError(AKISMET_SERVER_NOT_FOUND)) {
            // current Akismet docs POST to rest.akismet.com directly
            // (the api_key is sent in the POST body, not as a Host prefix)
            $request  =
                strToUpper($type)." /{$this->akismetVersion}/$path HTTP/1.0\r\n" .
                "Host: {$this->host}\r\n" .
                "Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\n" .
                "Content-Length: ".strlen($request)."\r\n" .
                "User-Agent: Akismet PHP4 Class\r\n" .
                "\r\n" .
                $request
                ;
            $response = "";

            @fwrite($this->con, $request);

            while(!feof($this->con)) {
                $response .= @fgets($this->con, $responseLength);
            }

            $response = explode("\r\n\r\n", $response, 2);
            return $response[1];
        } else {
            $this->setError(AKISMET_RESPONSE_FAILED, "The response could not be retrieved.");
        }
        
        $this->_disconnect();
    }
    
    
    // Connect to the Akismet server and store that connection in the instance variable $con
    function _connect() {
        $host = ($this->port == 443 ? 'ssl://' : '') . $this->host;
        if(!($this->con = @fsockopen($host, $this->port))) {
            $this->setError(AKISMET_SERVER_NOT_FOUND, "Could not connect to akismet server.");
        }
    }
    
    
    // Close the connection to the Akismet server
    // fclose(false) raises TypeError on PHP 8 when the connection failed
    function _disconnect() {
        if (is_resource($this->con)) fclose($this->con);
    }
    
    
}





// The controlling class. This is the ONLY class the user should instantiate in
// order to use the Akismet service!
class Akismet extends AkismetObject {
    var $apiPort = 443;
    var $akismetServer = 'rest.akismet.com';
    var $akismetVersion = '1.1';
    var $http;
    
    var $ignore = array(
                        'HTTP_COOKIE',
                        'HTTP_X_FORWARDED_FOR',
                        'HTTP_X_FORWARDED_HOST',
                        'HTTP_MAX_FORWARDS',
                        'HTTP_X_FORWARDED_SERVER',
                        'REDIRECT_STATUS',
                        'SERVER_PORT',
                        'PATH',
                        'DOCUMENT_ROOT',
                        'SERVER_ADMIN',
                        'QUERY_STRING',
                        'PHP_SELF'
                        );
    
    var $blogUrl = "";
    var $apiKey  = "";
    var $comment = array();
    
    
    /**
     * Constructor
     * 
     * Set instance variables, connect to Akismet, and check API key
     * 
     * @param    String    $blogUrl    The URL to your own blog
     * @param     String    $apiKey        Your wordpress API key
     * @param     String[]    $comment    A formatted comment array to be examined by the Akismet service
     */
    function __construct($blogUrl, $apiKey, $comment) {
        $this->blogUrl = $blogUrl;
        $this->apiKey  = $apiKey;
        
        // Populate the comment array with information needed by Akismet
        $this->comment = $comment;
        $this->_formatCommentArray();
        
        if(!isset($this->comment['user_ip'])) {
            $this->comment['user_ip'] = ($_SERVER['REMOTE_ADDR'] != getenv('SERVER_ADDR')) ? $_SERVER['REMOTE_ADDR'] : getenv('HTTP_X_FORWARDED_FOR');
        }
        if(!isset($this->comment['user_agent'])) {
            $this->comment['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }
        if(!isset($this->comment['referrer'])) {
            $this->comment['referrer'] = $_SERVER['HTTP_REFERER'];
        }
        $this->comment['blog'] = $blogUrl;
        
        // Connect to the Akismet server and populate errors if they exist
        $this->http = new AkismetHttpClient($this->akismetServer, $blogUrl, $apiKey);
        if($this->http->errorsExist()) {
            $this->errors = array_merge($this->errors, $this->http->getErrors());
        }
        
        // Check if the API key is valid
        if(!$this->_isValidApiKey($apiKey)) {
            $this->setError(AKISMET_INVALID_KEY, "Your Akismet API key is not valid.");
        }
        // connection errors raised during the key check live on the
        // http client; copy them (preserving keys) so callers can distinguish
        // a server outage from a genuinely invalid key
        if($this->http->errorsExist()) {
            $this->errors = $this->errors + $this->http->getErrors();
        }
    }
    
    
    /**
     * Query the Akismet and determine if the comment is spam or not
     * 
     * @return    boolean
     */
    function isSpam() {
        $response = $this->http->getResponse($this->_getQueryString(), 'comment-check');
        
        return ($response == "true");
    }
    
    
    /**
     * Submit this comment as an unchecked spam to the Akismet server
     * 
     * @return    void
     */
    function submitSpam() {
        $this->http->getResponse($this->_getQueryString(), 'submit-spam');
    }
    
    
    /**
     * Submit a false-positive comment as "ham" to the Akismet server
     *
     * @return    void
     */
    function submitHam() {
        $this->http->getResponse($this->_getQueryString(), 'submit-ham');
    }
    
    
    /**
     * Check with the Akismet server to determine if the API key is valid
     *
     * @access    Protected
     * @param    String    $key    The Wordpress API key passed from the constructor argument
     * @return    boolean
     */
    function _isValidApiKey($key) {
        // current Akismet docs use api_key= (was key=)
        $keyCheck = $this->http->getResponse("api_key=".urlencode($this->apiKey)."&blog=".urlencode($this->blogUrl), 'verify-key');

        return ($keyCheck == "valid");
    }
    
    
    /**
     * Format the comment array in accordance to the Akismet API
     *
     * @access    Protected
     * @return    void
     */
    function _formatCommentArray() {
        $format = array(
                        'type' => 'comment_type',
                        'author' => 'comment_author',
                        'email' => 'comment_author_email',
                        'website' => 'comment_author_url',
                        'body' => 'comment_content'
                        );
        
        foreach($format as $short => $long) {
            if(isset($this->comment[$short])) {
                $this->comment[$long] = $this->comment[$short];
                unset($this->comment[$short]);
            }
        }
    }
    
    
    /**
     * Build a query string for use with HTTP requests
     *
     * @access    Protected
     * @return    String
     */
    function _getQueryString() {
        foreach($_SERVER as $key => $value) {
            if(!in_array($key, $this->ignore)) {
                if($key == 'REMOTE_ADDR') {
                    $this->comment[$key] = $this->comment['user_ip'];
                } else {
                    $this->comment[$key] = $value;
                }
            }
        }

        // current Akismet docs require api_key in the POST body
        $query_string = 'api_key=' . urlencode($this->apiKey) . '&';

        foreach($this->comment as $key => $data) {
            // $_SERVER may contain array values (e.g. argv);
            // stripslashes(array) is fatal on PHP 8
            if (! is_string($data) && ! is_numeric($data)) continue;
            $query_string .= $key . '=' . urlencode(stripslashes($data)) . '&';
        }

        return $query_string;
    }


}

} // end of class_exists('AkismetObject') guard
?>
