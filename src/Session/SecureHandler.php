<?php
/**
 * User: <macro_fengye@163.com> Macro Chen
 * Date: 2016/11/4
 * Time: 19:32
 */

namespace Polymer\Session;

class SecureHandler extends \SessionHandler
{
    /**
     * Encryption and authentication key
     *
     * @var string
     */
    protected $key;

    /**
     * Constructor
     * @throws \Exception
     */
    public function __construct()
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException(sprintf(
                'You need the OpenSSL extension to use %s',
                __CLASS__
            ));
        }
        if (!extension_loaded('mbstring')) {
            throw new \RuntimeException(sprintf(
                'You need the MultiBytes extension to use %s',
                __CLASS__
            ));
        }
    }

    /**
     * Open the session
     *
     * @param string $save_path
     * @param string $session_name
     * @return bool
     */
    public function open($save_path, $session_name)
    {
        $this->key = $this->getKey('KEY_' . $session_name);
        return parent::open($save_path, $session_name);
    }

    /**
     * Read from session and decrypt
     *
     * @param string $id
     * @return string
     */
    public function read($id)
    {
        $data = parent::read($id);
        return empty($data) ? '' : $this->decrypt($data, $this->key);
    }

    /**
     * Encrypt the data and write into the session
     *
     * @param string $id
     * @param string $data
     * @return bool
     */
    public function write($id, $data)
    {
        return parent::write($id, $this->encrypt($data, $this->key));
    }

    /**
     * Encrypt and authenticate
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    protected function encrypt($data, $key)
    {
        $iv = random_bytes(16); // AES block size in CBC mode
        // Encryption
        $cipherText = openssl_encrypt(
            $data,
            'AES-256-CBC',
            mb_substr($key, 0, 32, '8bit'),
            OPENSSL_RAW_DATA,
            $iv
        );
        // Authentication
        $hMac = hash_hmac(
            'SHA256',
            $iv . $cipherText,
            mb_substr($key, 32, null, '8bit'),
            true
        );
        return $hMac . $iv . $cipherText;
    }

    /**
     * Authenticate and decrypt
     *
     * @param string $data
     * @param string $key
     * @throws \Exception
     * @return string
     */
    protected function decrypt($data, $key)
    {
        $hMac = mb_substr($data, 0, 32, '8bit');
        $iv = mb_substr($data, 32, 16, '8bit');
        $cipherText = mb_substr($data, 48, null, '8bit');
        // Authentication
        $hMacNew = hash_hmac(
            'SHA256',
            $iv . $cipherText,
            mb_substr($key, 32, null, '8bit'),
            true
        );
        if (!$this->hash_equals($hMac, $hMacNew)) {
            throw new \RuntimeException('Authentication failed');
        }
        // Decrypt
        return openssl_decrypt(
            $cipherText,
            'AES-256-CBC',
            mb_substr($key, 0, 32, '8bit'),
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    /**
     * Get the encryption and authentication keys from cookie
     *
     * @param string $name
     * @return string
     */
    protected function getKey($name)
    {
        if (empty($_COOKIE[$name])) {
            $key = random_bytes(64); // 32 for encryption and 32 for authentication
            $cookieParam = session_get_cookie_params();
            setcookie(
                $name,
                base64_encode($key),
                // if session cookie lifetime > 0 then add to current time
                // otherwise leave it as zero, honoring zero's special meaning
                // expire at browser close.
                ($cookieParam['lifetime'] > 0) ? time() + $cookieParam['lifetime'] : 0,
                $cookieParam['path'],
                $cookieParam['domain'],
                $cookieParam['secure'],
                $cookieParam['httponly']
            );
        } else {
            $key = base64_decode($_COOKIE[$name]);
        }
        return $key;
    }

    /**
     * Hash equals function for PHP 5.5+
     *
     * @param string $expected
     * @param string $actual
     * @return bool
     */
    protected function hash_equals($expected, $actual)
    {
        $expected = (string)$expected;
        $actual = (string)$actual;
        if (function_exists('hash_equals')) {
            return hash_equals($expected, $actual);
        }
        $lenExpected = mb_strlen($expected, '8bit');
        $lenActual = mb_strlen($actual, '8bit');
        $len = min($lenExpected, $lenActual);
        $result = 0;
        for ($i = 0; $i < $len; $i++) {
            $result |= ord($expected[$i]) ^ ord($actual[$i]);
        }
        $result |= $lenExpected ^ $lenActual;
        return ($result === 0);
    }
}