<?php
namespace Limbonia\Email;

/**
 * Limbonia Email Class
 *
 * This is a wrapper around the PHP mail command that allows for object oriented usage
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia
 */
class Email
{
  /**
   * List of addresses to send the email to
   *
   * @var array
   */
  protected $aTo = [];

  /**
   * List of addresses to CC the email to
   *
   * @var array
   */
  protected $aCC = [];

  /**
   * List of addresses to BCC the email to
   *
   * @var array
   */
  protected $aBCC = [];

  /**
   * The subject of the email being sent
   *
   * @var string
   */
  protected $sSubject = '';

  /**
   * The from string of the email being sent
   *
   * @var string
   */
  protected $sFrom = '';

  /**
   * The body of the email being sent
   *
   * @var string
   */
  protected $sBody = '';

  /**
   * The path of the attachment
   *
   * @var string
   */
  protected $sAttachment = '';

  /**
   * The mime boundary used  attachments
   *
   * @var string
   */
  protected $sMimeBoundary = '';

  /**
   * Return the properly decoded body text, if possible
   *
   * @param array $hHeaders
   * @param string $sBody
   * @return string
   */
  public static function decodeBody($hHeaders, $sBody)
  {
    if (empty($hHeaders['content-transfer-encoding']))
    {
      return $sBody;
    }

    switch (strtolower($hHeaders['content-transfer-encoding']))
    {
      case '7bit':
      case '8bit':
        return $sBody;

      case 'base64':
        return base64_decode($sBody);

      case 'quoted_printable':
      case 'quoted-printable':
        return quoted_printable_decode($sBody);
    }

    echo "\nInvalid transfer-encoding found: {$hHeaders['content-transfer-encoding']}\n";
    return $sBody;
  }

  /**
   * Is the message associated with the specified headers a text type?
   *
   * @param array $hHeaders
   * @return boolean
   */
  public static function isText(array $hHeaders = [])
  {
    if (isset($hHeaders['content-type']) && preg_match("#text/(plain|html)#", $hHeaders['content-type'], $aMatch))
    {
      return $aMatch[1] == 'html' ? 'html' : 'text';
    }

    return false;
  }

  /**
   * Is the message associated with the specified headers an attachment?
   *
   * @param array $hHeaders
   * @return boolean
   */
  public static function isAttachment($hHeaders)
  {
    if (isset($hHeaders['content-disposition']) && preg_match("#attachment; filename=\"(.*?)\"#", $hHeaders['content-disposition'], $aMatch))
    {
      return $aMatch[1];
    }

    return false;
  }

  /**
   * Is the message associated with the specified headers a multi-part message?
   *
   * @param array $hHeaders
   * @return boolean
   */
  public static function isMultiPart($hHeaders)
  {
    if (isset($hHeaders['content-type']) && preg_match("#multipart/.*?; boundary=\"(.*?)\"#", $hHeaders['content-type'], $aMatch))
    {
      return $aMatch[1];
    }

    return false;
  }

  /**
   * Return the hash of header data based on the specified raw headers
   *
   * @param array|string $xRawHeader - the raw header data (either and array or text data)
   * @return array
   */
  public static function processHeaders($xRawHeader)
  {
    $aRawHeader = is_array($xRawHeader) ? $xRawHeader : explode("\n", $xRawHeader);
    $hHeaders = [];
    $sPrevMatch = '';

    foreach ($aRawHeader as $sLine)
    {
      if (preg_match('/^([a-z][a-z0-9-_]+):/is', $sLine, $aMatch))
      {
        $sHeaderName = strtolower($aMatch[1]);
        $sPrevMatch = $sHeaderName;
        $hHeaders[$sHeaderName] = trim(substr($sLine, strlen($sHeaderName) + 1));
      }
      else
      {
        if (!empty($sPrevMatch))
        {
          $hHeaders[$sPrevMatch] .= ' ' . $sLine;
        }
      }
    }

    return $hHeaders;
  }

  /**
   * Break apart the message into the headers and message text
   *
   * @param array|string $xMessage - the message data
   * @return array
   */
  public static function breakMessage($xMessage)
  {
    $aBody = is_array($xMessage) ? $xMessage : explode("\n", $xMessage);
    $aHeaders = [];

    while ($sLine = array_shift($aBody))
    {
      if (empty($sLine))
      {
        break;
      }

      $aHeaders[] = $sLine;
    }

    return
    [
      'headers' => self::processHeaders($aHeaders),
      'body' => implode("\n", $aBody)
    ];
  }

  /**
   * Process the raw message data and return a hash of data
   *
   * @param array|string $xMessage - the message data
   * @return string
   */
  public static function processMessage($xMessage)
  {
    $hMessage = self::breakMessage($xMessage);

    $sBody = trim($hMessage['body']);
    unset($hMessage['body']);

    if ($sFileName = self::isAttachment($hMessage['headers']))
    {
      $hMessage['attachment'] =
      [
        'filename' => $sFileName,
        'data' => self::decodeBody($hMessage['headers'], $sBody)
      ];

      if (isset($hMessage['headers']['content-type']) && preg_match("#^(.*?);#", $hMessage['headers']['content-type'], $aMatch))
      {
        $hMessage['content-type'] = $aMatch[1];
      }

      $hMessage['type'] = 'attachment';
      return $hMessage;
    }

    if ($sBodyType = self::isText($hMessage['headers']))
    {
      $hMessage[$sBodyType] = self::decodeBody($hMessage['headers'], $sBody);
      $hMessage['type'] = $sBodyType;
      return $hMessage;
    }

    if ($sBoundary = self::isMultiPart($hMessage['headers']))
    {
      $aPartList = explode('--' . $sBoundary, $sBody);
      array_shift($aPartList);

      foreach ($aPartList as $sPart)
      {
        $hProcessedMessage = self::processMessage(trim($sPart));

        if (!isset($hProcessedMessage['type']))
        {

          if (count($hProcessedMessage) == 1 && isset($hProcessedMessage['headers']))
          {
            continue;
          }

          unset($hProcessedMessage['headers']);

          if (!isset($hMessage['part']))
          {
            $hMessage['part'] = [];
          }

          $hMessage['part'][] = $hProcessedMessage;
          continue;
        }

        $sType = $hProcessedMessage['type'];
        unset($hProcessedMessage['type']);

        if (empty($hProcessedMessage))
        {
          continue;
        }

        if (isset($hMessage[$sType]))
        {
          if (!is_array($hMessage[$sType]) || !isset($hMessage[$sType][0]))
          {
            $xTemp = $hMessage[$sType];
            unset($hMessage[$sType]);
            $hMessage[$sType] = [$xTemp];
          }

          $hMessage[$sType][] = $hProcessedMessage[$sType];
        }
        else
        {
          $hMessage[$sType] = $hProcessedMessage[$sType];
        }
      }

      if (!isset($hMessage['text']) && !isset($hMessage['email']) && isset($hMessage['part'][0]))
      {
        if (isset($hMessage['part'][0]['text']))
        {
          $hMessage['text'] = $hMessage['part'][0]['text'];
          unset($hMessage['part'][0]['text']);
        }

        if (isset($hMessage['part'][0]['html']))
        {
          $hMessage['html'] = $hMessage['part'][0]['html'];
          unset($hMessage['part'][0]['html']);
        }

        if (empty($hMessage['part'][0]))
        {
          unset($hMessage['part'][0]);
        }

        if (empty($hMessage['part']))
        {
          unset($hMessage['part']);
        }
      }

      return $hMessage;
    }

    if (!empty($sBody))
    {
      $hMessage['body'] = $sBody;
      $hMessage['type'] = 'body';
    }

    return $hMessage;
  }

    /**
   * Validate the specified email address
   *
   * @param string $sEmailAddress
   * @param boolean $bUseDNS (optional) - Use DNS to validate the email's domain? (defaults to true)
   * @throws \Exception
   */
  public static function validate($sEmailAddress, $bUseDNS = true)
  {
    if (preg_match("/.*?<(.*?)>/", $sEmailAddress, $aMatch))
    {
      $sEmailAddress = $aMatch[1];
    }

    if (empty($sEmailAddress))
    {
      throw new \Exception('Email address is empty');
    }

    if (strpos($sEmailAddress, ' ') !== false)
    {
      throw new \Exception('Email address is *not* allowed to have spaces in it');
    }

    $iAtIndex = strrpos($sEmailAddress, "@");

    if (false === $iAtIndex)
    {
      throw new \Exception("Email address does not contain an 'at sign' (@)");
    }

    $sLocal = substr($sEmailAddress, 0, $iAtIndex);
    $sLocalLen = strlen($sLocal);

    if ($sLocalLen < 1)
    {
      throw new \Exception("The 'local' part of the email address is empty");
    }

    if ($sLocalLen > 64)
    {
      throw new \Exception("The 'local' part of the email address is too long");
    }

    $sDomain = substr($sEmailAddress, $iAtIndex + 1);
    $sDomainLen = strlen($sDomain);

    if ($sDomainLen < 1)
    {
      throw new \Exception("The 'domain' part of the email address is empty");
    }

    if ($sDomainLen > 255)
    {
      throw new \Exception("The 'domain' part of the email address is too long");
    }

    if ($sLocal[0] == '.')
    {
      throw new \Exception("The 'local' part of the email address starts with a 'dot' (.)");
    }

    if ($sLocal[$sLocalLen - 1] == '.')
    {
      throw new \Exception("The 'local' part of the email address ends with a 'dot' (.)");
    }

    if (preg_match('/\\.\\./', $sLocal))
    {
      throw new \Exception("The 'local' part of the email address has two consecutive dots (..)");
    }

    if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $sDomain))
    {
      throw new \Exception("The 'domain' part of the email address contains invalid characters");
    }

    if (preg_match('/\\.\\./', $sDomain))
    {
      throw new \Exception("The 'domain' part of the email address has two consecutive dots (..)");
    }

    $sSlashLight = str_replace("\\\\", "", $sLocal);

    //these characters are invalid
    if (!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/', $sSlashLight))
    {
      //unless the whole thing is quoted
      if (!preg_match('/^"(\\\\"|[^"])+"$/', $sSlashLight))
      {
        throw new \Exception("The 'local' part of the email address contains invalid characters");
      }
    }

    if ($bUseDNS)
    {
      if (!checkdnsrr($sDomain, "MX") && !checkdnsrr($sDomain, "A"))
      {
        throw new \Exception("The 'domain' part of the email address has no valid DNS");
      }
    }
  }

  /**
   * Constructor
   */
  public function __construct(array $hConfig = [])
  {
    if (isset($hConfig['from']))
    {
      $this->setFrom($hConfig['from']);
    }

    if (isset($hConfig['to']))
    {
      $this->addTo($hConfig['to']);
    }

    if (isset($hConfig['cc']))
    {
      $this->addCC($hConfig['cc']);
    }

    if (isset($hConfig['bcc']))
    {
      $this->addBCC($hConfig['bcc']);
    }

    if (isset($hConfig['subject']))
    {
      $this->setSubject($hConfig['subject']);
    }

    if (isset($hConfig['body']))
    {
      $this->addBody($hConfig['body']);
    }

    $this->sMimeBoundary = isset($hConfig['mimeboundary']) ? $hConfig['mimeboundary'] : "::[" . md5(time()) . "]::";

    if (isset($hConfig['body']))
    {
      $this->addBody($hConfig['body']);
    }
  }

  /**
   * Add one or more email addresses to the "To" array
   *
   * @param string|array $xEmailAddress - Either a single address or an array of addresses
   */
  public function addTo($xEmailAddress)
  {
    $aEmailAddress = (array)$xEmailAddress;

    foreach ($aEmailAddress as $sEmailAddress)
    {
      $sEmailAddress = trim($sEmailAddress);

      try
      {
        self::validate($sEmailAddress, false);
        $this->aTo[] = $sEmailAddress;
      }
      catch (\Exception $e) {}
    }

    $this->aTo = array_unique($this->aTo);
  }

  /**
   * Return a comma separated list of to email addresses
   *
   * @return string
   */
  public function getTo()
  {
    return implode(', ', $this->aTo);
  }

  /**
   * Add one or more email addresses to the "CC" array
   *
   * @param string|array $xEmailAddress - Either a single address or an array of addresses
   */
  public function addCC($xEmailAddress)
  {
    $aEmailAddress = (array)$xEmailAddress;

    foreach ($aEmailAddress as $sEmailAddress)
    {
      $sEmailAddress = trim($sEmailAddress);

      try
      {
        self::validate($sEmailAddress, false);
        $this->aCC[] = $sEmailAddress;
      }
      catch (\Exception $e) {}
    }

    $this->aCC = array_unique($this->aCC);
  }

  /**
   * Add one or more email addresses to the "BCC" array
   *
   * @param string|array $xEmailAddress - Either a single address or an array of addresses
   */
  public function addBCC($xEmailAddress)
  {
    $aEmailAddress = (array)$xEmailAddress;

    foreach ($aEmailAddress as $sEmailAddress)
    {
      $sEmailAddress = trim($sEmailAddress);

      try
      {
        self::validate($sEmailAddress, false);
        $this->aBCC[] = $sEmailAddress;
      }
      catch (\Exception $e) {}
    }

    $this->aBCC = array_unique($this->aBCC);
  }

  /**
   * Set the "From" address to the specified address
   *
   * @param string $sEmailAddress
   */
  public function setFrom($sEmailAddress)
  {
    $this->sFrom = trim($sEmailAddress);
  }

  /**
   * Set the "Subject" to the specified subject
   *
   * @param string $sSubject
   */
  public function setSubject($sSubject)
  {
    $this->sSubject = trim(preg_replace('/\n|\r/', ' ', $sSubject));
  }

  /**
   * Set the "Body" to the specified body
   *
   * @param string $sText
   */
  public function addBody($sText)
  {
    $this->sBody .= $sText;
  }

  /**
   * Generate and return the body of the email based on previously specified data
   *
   * @return string
   */
  public function getBody()
  {
    if (is_readable($this->sAttachment))
    {
      $sFileName = basename($this->sAttachment);
      $rFile = fopen($this->sAttachment, 'r');
      $sAttachmentRaw = fread($rFile, filesize($this->sAttachment));
      $sAttachment = chunk_split(base64_encode($sAttachmentRaw));
      fclose($rFile);

      $sBody  = "";
      $sBody .= "--" . $this->sMimeBoundary . "\r\n";
      $sBody .= "Content-Type: text/plain; charset=\"iso-8859-1\"\r\n";
      $sBody .= "Content-Transfer-Encoding: 7bit\r\n";
      $sBody .= "\r\n";
      $sBody .= $this->sBody;
      $sBody .= "\r\n";
      $sBody .= "--" . $this->sMimeBoundary . "\r\n";
      $sBody .= "Content-Type: application/octet-stream;";
      $sBody .= "name=\"$sFileName\"\r\n";
      $sBody .= "Content-Transfer-Encoding: base64\r\n";
      $sBody .= "Content-Disposition: attachment;";
      $sBody .= " filename=\"$sFileName\"\r\n";
      $sBody .= "\r\n";
      $sBody .= $sAttachment;
      $sBody .= "\r\n";
      $sBody .= "--" . $this->sMimeBoundary . "--\r\n";
      return $sBody;
    }

    return $this->sBody;
  }

  /**
   * Set the path to the specified attachment
   *
   * @param string $sAttachment
   */
  public function setAttachment($sAttachment)
  {
    $this->sAttachment = trim($sAttachment);
  }

  /**
   * Generate and return a string of header data
   *
   * @return string
   */
  public function getHeaders()
  {
    $sHeader = 'From: ' . $this->sFrom . "\r\n";

    if (!empty($this->aCC))
    {
      $sHeader .= 'Cc: ' . implode(', ', $this->aCC) . "\r\n";
    }

    if (!empty($this->aBCC))
    {
      $sHeader .= 'Bcc: ' . implode(', ', $this->aBCC) . "\r\n";
    }

    if (is_readable($this->sAttachment))
    {
      $sHeaders .= "MIME-Version: 1.0\r\n";
      $sHeaders .= "Content-Type: multipart/mixed; boundary=\"" . $this->sMimeBoundary . "\";\r\n";
    }
    else
    {
      $sHeader .= "Content-type: text/html; charset=utf8\r\n";
    }

    $sHeader .= "X-Mailer: Limbonia\r\n";
    return $sHeader;
  }

  /**
   * Send the currently configured email
   *
   * @return boolean - true on success and false on failure
   */
  public function send()
  {
    if (empty($this->aTo))
    {
      return false;
    }

    return mail($this->getTo(), $this->sSubject, $this->getBody(), $this->getHeaders());
  }
}