<?php
namespace Limbonia\Email;

/**
 * Limbonia Email Utility Class
 *
 * This is a utility for email and email address processing
 *
 * @author Lonnie Blansett <lonnie@limbonia.tech>
 * @package Limbonia-Email
 */
class Util
{
  /**
   * Validate the specified email address
   *
   * @param string $sEmailAddress
   * @param boolean $bUseDNS (optional) - Use DNS to validate the email's domain? (defaults to false)
   * @throws \Exception
   */
  public static function validate($sEmailAddress, $bUseDNS = false)
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
}