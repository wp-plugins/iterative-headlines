<?php
/*
 * Provides tools to mixin MAC message storage functionality... note that if you want it
 * /encrypted/ in addition to validated you should override prepareCiphertext/Plaintext
 * this is a bit sloppy, but it is implemented this way so that we can provide JSON
 * wrapped functionality (so we can take in "any" primitive datatype).
 *
 * Even if you don't want it, prepareCiphertext (the prior-to-decoding prepare function)
 * and preparePlaintext (the prior-to-encoding prepare function) can be extended as you wish.
 * 
 * Stupidly relies on $this->key_hmac being the key. This should be more clear/flexible.
 */
class Iterative_MACComputer {
  public static function prepareCiphertext($ciphertext) {
    return $ciphertext;
  }
  public static function preparePlaintext($plaintext) {
    return $plaintext;
  }
  /*
   * Implements encrypt-then-authenticate as described by Moxie Marlinspike.
   * Reads a message and returns the message as it was saved from prepareMessage.
   */
  public static function readMessage($message, $hash, $key) {
      // NOTE: empty has some strange behavior if you store "0". It should probably be discarded here.
      // Perhaps not that important since we JSON encode.
      if($message === false || $hash === false || empty($message) || empty($hash))
        return false;
      // always authenticate as a first step, exit if it doesn't pass: http://www.thoughtcrime.org/blog/the-cryptographic-doom-principle/
      // this should be the step that any user modified messages get dumped. if anything bad happens after this, we must assume it is
      // a security risk.
      
      if(self::validateHash($message, $hash, $key) === false) {
        return false;
      }

      $message = self::prepareCiphertext($message);
      return json_decode($message, true);
  }
  /*
   * Implements encrypt-then-authenticate if there were encryption specified.
   * See: http://www.thoughtcrime.org/blog/the-cryptographic-doom-principle/
   *
   * Takes in a message and returns a dictionary of 'message' and 'hash' strings
   * where 'message' is the (possibly encrypted) plaintext and 'hash' is the validation
   * string to be passed in to readMessage.
   */
  public static function prepareMessage($message, $key) {
    $plaintext = json_encode($message);
    $ciphertext = self::preparePlaintext($plaintext);
    $hash = self::hash($ciphertext, $key);
    if($hash === false)
       throw new RuntimeException("Cowardly refusing to return ciphertext when hash calculation fails. Check that the appropriate HMAC algorithm is available.");
    return array('message' => $ciphertext, 'hash' => $hash);
  }
  /*
   * Validates a hash in a timing attack aware manner.
   */
  public static function validateHash($message, $hash, $key) {
    if(!hash_equals(self::hash($message, $key), $hash))
      return false;
    return true;
  }
  /*
   * Computes a hash as a base64 encoded sha256.
   */
  public static function hash($message, $secret) {
    $hash = hash_hmac('sha256', $message, $secret);
    $hash = base64_encode($hash); // we do not decode the hash ever at this point, only compare as base64 encoded hashes.
    $hash = substr($hash, 0, 8); 	// truncate teh hash because we use a huge set of cookies and there's no security requirements here. 
    if($hash === false) // but base64_encode has a note in the documentation that it may return false.
      throw new RuntimeException("Failed to compute hash because base64 failed.");
    return $hash;
  }
}

if(!function_exists('hash_equals')) {
  function hash_equals($str1, $str2) {
    if(strlen($str1) != strlen($str2)) {
      return false;
    } else {
      $res = $str1 ^ $str2;
      $ret = 0;
      for($i = strlen($res) - 1; $i >= 0; $i--) $ret |= ord($res[$i]);
      return !$ret;
    }
  }
}
?>
