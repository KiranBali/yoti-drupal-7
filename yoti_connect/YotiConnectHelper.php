<?php
use Yoti\ActivityDetails;
use Yoti\YotiClient;

require_once __DIR__ . '/sdk/boot.php';


/**
 * Class YotiConnectHelper
 *
 * @package Drupal\yoti_connect
 *
 */
class YotiConnectHelper
{
    /**
     * @var array
     */
    public static $profileFields = array(
        ActivityDetails::ATTR_SELFIE => 'Selfie',
        ActivityDetails::ATTR_PHONE_NUMBER => 'Phone number',
        ActivityDetails::ATTR_DATE_OF_BIRTH => 'Date of birth',
        ActivityDetails::ATTR_GIVEN_NAMES => 'Given names',
        ActivityDetails::ATTR_FAMILY_NAME => 'Family name',
        ActivityDetails::ATTR_NATIONALITY => 'Nationality',
    );

    /**
     * Running mock requests instead of going to yoti
     * @return bool
     */
    public static function mockRequests()
    {
        return defined('YOTI_MOCK_REQUEST') && YOTI_MOCK_REQUEST;
    }

    /**
     * @return bool
     */
    public function link()
    {
        global $user;

        $currentUser = $user;
        $config = self::getConfig();
        $token = (!empty($_GET['token'])) ? $_GET['token'] : null;

        // if no token then ignore
        if (!$token)
        {
            $this->setFlash('Could not get Yoti token.', 'error');

            return false;
        }

        // init yoti client and attempt to request user details
        try
        {
            $yotiClient = new YotiClient($config['yoti_sdk_id'], $config['yoti_pem']['contents']);
            $yotiClient->setMockRequests(self::mockRequests());
            $activityDetails = $yotiClient->getActivityDetails($token);
        }
        catch (Exception $e)
        {
            $this->setFlash('Yoti could not successfully connect to your account.', 'error');

            return false;
        }

        // if unsuccessful then bail
        if ($yotiClient->getOutcome() != YotiClient::OUTCOME_SUCCESS)
        {
            $this->setFlash('Yoti could not successfully connect to your account.', 'error');

            return false;
        }

        // check if yoti user exists
        $userId = $this->getUserIdByYotiId($activityDetails->getUserId());

        // if yoti user exists in db but isn't an actual account then remove it from yoti table
        if ($userId && $currentUser && $currentUser->uid != $userId && !user_load($userId))
        {
            // remove users account
            $this->deleteYotiUser($userId);
        }

        // if user isn't logged in
        if (!$currentUser->uid)
        {
            // register new user
            if (!$userId)
            {
                $errMsg = $userId = null;
                try
                {
                    $userId = $this->createUser($activityDetails);
                }
                catch (Exception $e)
                {
                    $errMsg = $e->getMessage();
                }

                // no user id? no account
                if (!$userId)
                {
                    // if couldn't create user then bail
                    $this->setFlash("Could not create user account. $errMsg", 'error');

                    return false;
                }
            }

            // log user in
            $this->loginUser($userId);
        }
        else
        {
            // if current logged in user doesn't match yoti user registered then bail
            if ($userId && $currentUser->uid != $userId)
            {
                $this->setFlash('This Yoti account is already linked to another account.', 'error');
            }
            // if joomla user not found in yoti table then create new yoti user
            elseif (!$userId)
            {
                $this->createYotiUser($currentUser->uid, $activityDetails);
                $this->setFlash('Your Yoti account has been successfully linked.');
            }
        }

        return true;
    }

    /**
     * Unlink account from currently logged in
     */
    public function unlink()
    {
        global $user;

        // unlink
        if ($user)
        {
            $this->deleteYotiUser($user->uid);
            return true;
        }

        return false;
    }

    /**
     * @param $message
     * @param string $type
     */
    private function setFlash($message, $type = 'status')
    {
        drupal_set_message($message, $type);
    }

    /**
     * @param string $prefix
     * @return string
     */
    private function generateUsername($prefix = 'yoticonnect-')
    {
        // generate username
        $i = 0;
        do
        {
            $username = $prefix . $i++;
        }
        while (user_load_by_name($username));

        return $username;
    }

    /**
     * @param $prefix
     * @param string $domain
     * @return string
     */
    private function generateEmail($prefix = 'yoticonnect-', $domain = 'example.com')
    {
        // generate email
        $i = 0;
        do
        {
            $email = $prefix . $i++ . "@$domain";
        }
        while (user_load_by_mail($email));

        return $email;
    }

    /**
     * @param int $length
     * @return string
     */
    private function generatePassword($length = 10)
    {
        // generate password
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $password = ''; //remember to declare $pass as an array
        $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
        for ($i = 0; $i < $length; $i++)
        {
            $n = rand(0, $alphaLength);
            $password .= $alphabet[$n];
        }

        return $password;
    }

    /**
     * @param ActivityDetails $activityDetails
     * @return int
     * @throws Exception
     */
    private function createUser(ActivityDetails $activityDetails)
    {
        $user = array(
            'status' => 1,
            //            'roles' => array(
            //                DRUPAL_AUTHENTICATED_RID => 'authenticated user',
            //                3 => 'custom role',
            //            ),
        );

        //Mandatory settings
        $user['pass'] = $this->generatePassword();
        $user['mail'] = $user['init'] = $this->generateEmail();
        $user['name'] = $this->generateUsername();//This username must be unique and accept only a-Z,0-9, - _ @ .

        // The first parameter is sent blank so a new user is created.
        $user = user_save('', $user);

        // set new id
        $userId = $user->uid;
        $this->createYotiUser($userId, $activityDetails);

        return $userId;
    }

    /**
     * @param $yotiId
     * @return int
     */
    private function getUserIdByYotiId($yotiId)
    {
        $tableName = self::tableName();
        $col = db_query("SELECT uid FROM `{$tableName}` WHERE identifier = '$yotiId'")->fetchCol();
        return ($col) ? reset($col) : null;
    }

    /**
     * @param $userId
     * @param ActivityDetails $activityDetails
     */
    private function createYotiUser($userId, ActivityDetails $activityDetails)
    {
        $meta = array();
        //        $user = user_load($userId);

        $selfieFilename = null;
        if (($content = $activityDetails->getProfileAttribute(ActivityDetails::ATTR_SELFIE)))
        {
            $uploadDir = self::uploadDir();
            if (!is_dir($uploadDir))
            {
                drupal_mkdir($uploadDir, 0777, true);
            }

            $selfieFilename = md5("selfie_$userId" . time()) . ".png";
            file_put_contents("$uploadDir/$selfieFilename", $content);
            //      file_put_contents(self::uploadDir() . "/$selfieFilename", $activityDetails->getUserProfile('selfie'));
            $meta['selfie_filename'] = $selfieFilename;
        }

        foreach (self::$profileFields as $param => $label)
        {
            $meta[$param] = $activityDetails->getProfileAttribute($param);
        }
        unset($meta[ActivityDetails::ATTR_SELFIE]);

        db_insert(self::tableName())->fields(array(
            'uid' => $userId,
            'identifier' => $activityDetails->getUserId(),
            'data' => serialize($meta),
        ))->execute();
    }

    /**
     * @param int $userId joomla user id
     */
    private function deleteYotiUser($userId)
    {
        db_delete(self::tableName())->condition("uid", $userId)->execute();
    }

    /**
     * @param $userId
     */
    private function loginUser($userId)
    {
        //        $user = user_load($userId);
        //        var_dump($user);exit;
        //        user_login_finalize($user);
        $form_state['uid'] = $userId;
        user_login_submit(array(), $form_state);
    }

    /**
     * not used in this instance
     * @return string
     */
    public static function tableName()
    {
        return 'users_yoti';
    }

    /**
     * @param bool $realPath
     * @return string
     */
    public static function uploadDir($realPath = true)
    {
        return ($realPath) ? drupal_realpath("yoti://") : 'yoti://';
    }

    /**
     * @return string
     */
    public static function uploadUrl()
    {
        return file_create_url(self::uploadDir());
    }

    /**
     * @return array
     */
    public static function getConfig()
    {
        if (self::mockRequests())
        {
            $config = require_once __DIR__ . '/sdk/sample-data/config.php';
            return $config;
        }

        $pem = variable_get('yoti_pem');
        $name = $contents = null;
        if ($pem)
        {
            $file = file_load($pem);
            $name = $file->uri;
            $contents = file_get_contents(drupal_realpath($name));
        }
        return array(
            'yoti_app_id' => variable_get('yoti_app_id'),
            'yoti_sdk_id' => variable_get('yoti_sdk_id'),
            'yoti_pem' => array(
                'name' => $name,
                'contents' => $contents,
            ),
        );
    }

    /**
     * @return null|string
     */
    public static function getLoginUrl()
    {
        $config = self::getConfig();
        if (empty($config['yoti_app_id']))
        {
            return null;
        }

        //https://staging0.www.yoti.com/connect/ad725294-be3a-4688-a26e-f6b2cc60fe70
        //https://staging0.www.yoti.com/connect/990a3996-5762-4e8a-aa64-cb406fdb0e68

        return YotiClient::getLoginUrl($config['yoti_app_id']);
    }
}