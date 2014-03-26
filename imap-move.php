#!/usr/bin/php
<?php
/**
    @file
    @brief Moves Mail from one IMAP account to another

Copyright (C) 2009 Edoceo, Inc

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Run Like:
    php ./imap-move.php \
        --source imap-ssl://userA:secret-password@imap.example.com:993/ \
        --target imap-ssl://userB:secret-passwrod@imap.example.com:993/sub-folder \
        [ --wipe --fake --copy ]

    --fake to just list what would be copied
    --wipe to remove messages after they are copied (move)
    --copy to store copies of the messages in a path

*/

error_reporting(E_ALL | E_STRICT);

_args($argc,$argv);

echo "Connecting Source...\n";
$S = new IMAP($_ENV['src']);

echo "Connecting Target...\n";
$T = new IMAP($_ENV['tgt']);
//$tgt_path_list = $T->listPath();
//print_r($tgt_path_list);

$src_path_list = $S->listPath();
// print_r($src_path_list);
// exit;

foreach ($src_path_list as $path) {

    echo "S: {$path['name']} = {$path['attribute']}\n";

    // Skip Logic Below
    if (_path_skip($path)) {
        echo "S: Skip\n";
        continue;
    }

    // Source Path
    $S->setPath($path['name']);
    $src_path_stat = $S->pathStat();
    // print_r($src_path_stat);
    if (empty($src_path_stat['mail_count'])) {
        echo "S: Skip {$src_path_stat['mail_count']} messages\n";
        continue;
    }
    echo "S: {$src_path_stat['mail_count']} messages\n";

    // Target Path
    $tgt_path = _path_map($path['name']);
    echo "T: Indexing: $tgt_path\n";
    $T->setPath($tgt_path); // Creates if needed
    // Show info on Target
    $tgt_path_stat = $T->pathStat();
    echo "T: {$tgt_path_stat['mail_count']} messages\n";
    // Build Index of Target
    $tgt_mail_list = array();
    for ($i=1;$i<=$tgt_path_stat['mail_count'];$i++) {
        $mail = $T->mailStat($i);
        if (array_key_exists('message_id', $mail))
          $tgt_mail_list[ $mail['message_id'] ] = !empty($mail['subject']) ? $mail['subject'] : "[ No Subject ] Message $i";
    }

    // print_r($tgt_mail_list);

    // for ($src_idx=1;$src_idx<=$src_path_stat['mail_count'];$src_idx++) {
    for ($src_idx=$src_path_stat['mail_count'];$src_idx>=1;$src_idx--) {

        $stat = $S->mailStat($src_idx);
        $stat['answered'] = trim($stat['Answered']);
        $stat['unseen'] = trim($stat['Unseen']);
        if (empty($stat['subject'])) $stat['subject'] = "[ No Subject ] Message $src_idx";
        // print_r($stat['message_id']); exit;

        if (array_key_exists('message_id', $stat) && array_key_exists($stat['message_id'],$tgt_mail_list)) {
            echo "S:$src_idx Mail: {$stat['subject']} Copied Already\n";
            $S->mailWipe($i);
            continue;
        }

        echo "S:$src_idx {$stat['subject']} ({$stat['MailDate']})\n   {$src_path_stat['path']} => ";
        if ($_ENV['fake']) {
            echo "\n";
            continue;
        }

        $S->mailGet($src_idx);
        $opts = array();
        if (empty($stat['unseen'])) $opts[] = '\Seen';
        if (!empty($stat['answered'])) {
            $opts[] = '\Answered';
        }
        $opts = implode(' ',$opts);
        $date = strftime('%d-%b-%Y %H:%M:%S +0000',strtotime($stat['MailDate']));

        if ($res = $T->mailPut(file_get_contents('mail'),$opts,$date)) {
            // echo "T: $res\n";
            $S->mailWipe($src_idx);
            echo "{$tgt_path_stat['path']}\n";
        } else {
            die("Fail to Put $res\n");
        }

        if ($_ENV['once']) die("--one and done\n");

    }
}

class IMAP
{
    private $_c; // Connection Handle
    private $_c_host; // Server Part {}
    private $_c_base; // Base Path Requested
    /**
        Connect to an IMAP
    */
    function __construct($uri)
    {
        $this->_c = null;
        $this->_c_host = sprintf('{%s',$uri['host']);
        if (!empty($uri['port'])) {
            $this->_c_host.= sprintf(':%d',$uri['port']);
        }
        switch (strtolower(@$uri['scheme'])) {
        case 'imap-ssl':
            $this->_c_host.= '/ssl';
            break;
        case 'imap-tls':
            $this->_c_host.= '/tls';
            break;
        case 'imap-novalidate-cert':
            $this->_c_host.= '/novalidate-cert';
            break;
        default:
        }
        $this->_c_host.= '}';

        $this->_c_base = $this->_c_host;
        // Append Path?
        if (!empty($uri['path'])) {
            $x = ltrim($uri['path'],'/');
            if (!empty($x)) {
                $this->_c_base = $x;
            }
        }
        echo "imap_open($this->_c_host)\n";
        $this->_c = imap_open($this->_c_host,$uri['user'],$uri['pass']);
        // echo implode(', ',imap_errors());
    }

    /**
        List folders matching pattern
        @param $pat * == all folders, % == folders at current level
    */
    function listPath($pat='*')
    {
        $ret = array();
        $list = imap_getmailboxes($this->_c, $this->_c_host,$pat);
        foreach ($list as $x) {
            $ret[] = array(
                'name' => $x->name,
                'attribute' => $x->attributes,
                'delimiter' => $x->delimiter,
            );
        }
        return $ret;
    }

    /**
        Get a Message
    */
    function mailGet($i)
    {
        // return imap_body($this->_c,$i,FT_PEEK);
        return imap_savebody($this->_c,'mail',$i,null,FT_PEEK);
    }

    /**
        Store a Message with proper date
    */
    function mailPut($mail,$opts,$date)
    {
        $stat = $this->pathStat();
        // print_r($stat);
        // $opts = '\\Draft'; // And Others?
        // $opts = null;
        // exit;
        if (empty($mail)) $mail = '<empty msg>'; // some IMAP servers will fatally fail if the msg is empty
        $ret = imap_append($this->_c,$stat['check_path'],$mail,$opts,$date);
        if ($buf = imap_errors()) {
            die(print_r($buf,true));
        }
        return $ret;

    }

    /**
        Message Info
    */
    function mailStat($i)
    {
        $head = imap_headerinfo($this->_c,$i);
        return (array)$head;
        // $stat = imap_fetch_overview($this->_c,$i);
        // return (array)$stat[0];
    }

    /**
        Immediately Delete and Expunge the message
    */
    function mailWipe($i)
    {
        if ( ($_ENV['wipe']) && (imap_delete($this->_c,$i)) ) return imap_expunge($this->_c);
    }

    /**
        Sets the Current Mailfolder, Creates if Needed
    */
    function setPath($p,$make=false)
    {
        // echo "setPath($p);\n";
        if (substr($p,0,1)!='{') {
            $p = $this->_c_host . trim($p,'/');
        }
        // echo "setPath($p);\n";

        $ret = imap_reopen($this->_c,$p); // Always returns true :(
        $buf = imap_errors();
        if (empty($buf)) {
            return true;
        }

        $buf = implode(', ',$buf);
        if (preg_match('/NONEXISTENT/',$buf)) {
            // Likley Couldn't Open on Gmail Side, So Create
            $ret = imap_createmailbox($this->_c,$p);
            $buf = imap_errors();
            if (empty($buf)) {
                // Reopen Again
                imap_reopen($this->_c,$p);
                return true;
            }
            die(print_r($buf,true)."\nFailed to Create setPath($p)\n");
        }
        die(print_r($buf,true)."\nFailed to Switch setPath($p)\n");
    }

    /**
        Returns Information about the current Path
    */
    function pathStat()
    {
        $res = imap_mailboxmsginfo($this->_c);
        $ret = array(
            'date' => $res->Date,
            'path' => $res->Mailbox,
            'mail_count' => $res->Nmsgs,
            'size' => $res->Size,
        );
        $res = imap_check($this->_c);
        $ret['check_date'] = $res->Date;
        $ret['check_mail_count'] = $res->Nmsgs;
        $ret['check_path'] = $res->Mailbox;
        // $ret = array_merge($ret,$res);
        return $ret;
    }
}

/**
    Process CLI Arguments
*/
function _args($argc,$argv)
{

    $_ENV['src'] = null;
    $_ENV['tgt'] = null;
    $_ENV['copy'] = false;
    $_ENV['fake'] = false;
    $_ENV['once'] = false;
    $_ENV['wipe'] = false;

    for ($i=1;$i<$argc;$i++) {
        switch ($argv[$i]) {
        case '--source':
        case '-s':
            $i++;
            if (!empty($argv[$i])) {
                $_ENV['src'] = parse_url($argv[$i]);
            }
            break;
        case '--target':
        case '-t': // Destination
            $i++;
            if (!empty($argv[$i])) {
                $_ENV['tgt'] = parse_url($argv[$i]);
            }
            break;
        case '--copy':
            // Given a Path to Copy To?
            $chk = $argv[$i+1];
            if (substr($chk,0,1)!='-') {
                $_ENV['copy_path'] = $chk;
                if (!is_dir($chk)) {
                    echo "Creating Copy Directory\n";
                    mkdir($chk,0755,true);
                }
                $i++;
            }
            break;
        case '--fake':
            $_ENV['fake'] = true;
            break;
        case '--once':
            $_ENV['once'] = true;
            break;
        case '--wipe':
            $_ENV['wipe'] = true;
            break;
        default:
            echo "arg: {$argv[$i]}\n";
        }
    }

    if ( (empty($_ENV['src']['path'])) || ($_ENV['src']['path']=='/') ) {
        $_ENV['src']['path'] = '/INBOX';
    }
    if ( (empty($_ENV['tgt']['path'])) || ($_ENV['tgt']['path']=='/') ) {
        $_ENV['tgt']['path'] = '/INBOX';
    }
}

/**
    @return mapped path name
*/
function _path_map($x)
{
    if (preg_match('/}(.+)$/',$x,$m)) {
        switch (strtolower($m[1])) {
        // case 'inbox':         return null;
        case 'deleted items': return '[Gmail]/Trash';
        case 'drafts':        return '[Gmail]/Drafts';
        case 'junk e-mail':   return '[Gmail]/Spam';
        case 'sent items':    return '[Gmail]/Sent Mail';
        }
        $x = str_replace('INBOX/',null,$m[1]);
    }
    return $x;
}

/**
    @return true if we should skip this path
*/
function _path_skip($path)
{
    if ( ($path['attribute'] & LATT_NOSELECT) == LATT_NOSELECT) {
        return true;
    }
    // All Mail, Trash, Starred have this attribute
    if ( ($path['attribute'] & 96) == 96) {
        return true;
    }

    // Skip by Pattern
    if (preg_match('/}(.+)$/',$path['name'],$m)) {
        switch (strtolower($m[1])) {
        case '[gmail]/all mail':
        case '[gmail]/sent mail':
        case '[gmail]/spam':
        case '[gmail]/starred':
            return true;
        }
    }

    // By First Folder Part of Name
    if (preg_match('/}([^\/]+)/',$path['name'],$m)) {
        switch (strtolower($m[1])) {
        // This bundle is from Exchange
        case 'journal':
        case 'notes':
        case 'outbox':
        case 'rss feeds':
        case 'sync issues':
            return true;
        }
    }

    return false;
}
