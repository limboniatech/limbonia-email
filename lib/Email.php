<?php
namespace Limbonia\Email;

/**
 * Limbonia Email Class
 *
 * This is a wrapper around the PHP mail command that allows for object oriented usage
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia-Email
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

  public function sendEmail(array $hConfig)
  {
    $oEmail = new self($hConfig);
    $oEmail->send();
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
        Util::validate($sEmailAddress, false);
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