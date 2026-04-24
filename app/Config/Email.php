<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Email extends BaseConfig
{
    public string $fromEmail;
    public string $fromName;
    public string $recipients = '';

    public string $userAgent = 'CodeIgniter';
    public string $protocol;
    public string $mailPath = '/usr/sbin/sendmail';

    public string $SMTPHost;
    public string $SMTPUser;
    public string $SMTPPass;
    public int    $SMTPPort;
    public int    $SMTPTimeout = 5;
    public bool   $SMTPKeepAlive = false;
    public string $SMTPCrypto;

    public bool $wordWrap = true;
    public int  $wrapChars = 76;
    public string $mailType;
    public string $charset;
    public bool $validate = false;
    public int  $priority = 3;
    public string $CRLF = "\r\n";
    public string $newline = "\r\n";

    public bool $BCCBatchMode = false;
    public int  $BCCBatchSize = 200;
    public bool $DSN = false;

    public function __construct()
    {
        parent::__construct();

        $this->fromEmail  = env('email.fromEmail', 'info@remoteestimation.us');
        $this->fromName   = env('email.fromName', 'Remote Estimation');

        $this->protocol   = env('email.protocol', 'smtp');

        $this->SMTPHost   = env('email.SMTPHost', 'smtp.gmail.com');
        $this->SMTPUser   = env('email.SMTPUser', '');
        $this->SMTPPass   = env('email.SMTPPass', '');
        $this->SMTPPort   = (int) env('email.SMTPPort', 587);
        $this->SMTPCrypto = env('email.SMTPCrypto', 'tls');

        $this->mailType   = env('email.mailType', 'html');
        $this->charset    = env('email.charset', 'UTF-8');
    }
}