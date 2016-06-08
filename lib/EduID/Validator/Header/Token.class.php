<?php
/* *********************************************************************** *
 *
 * *********************************************************************** */

namespace EduID\Validator\Data;

use EduID\Validator\Base as Validator;
use EduID\Model\Token as TokenModel;

class Token extends Validator {

    private $token;
    private $token_type;  // oauth specific
    private $token_key;

    private $token_info;  // provided by the client
    private $token_data;  // provided by the DB
    private $jwt_token;

    private $requireUUID = array();

    private $accept_type = array();
    private $accept_list = array();

    private $model;

    public function __construct($db) {
        parent::__construct($db);

        $this->model = new TokenModel($service->getDataBase());

        // header level
        $this->accept_list = array("Bearer",
                                   "MAC",
                                   "Basic");

        // check for the authorization header
        $headers = getallheaders();

        $this->log(json_encode($headers));

        if (array_key_exists("Authorization", $headers) &&
            isset($headers["Authorization"]) &&
            !empty($headers["Authorization"]))
        {

            $authheader = $headers["Authorization"];
            // $this->log("authorization header ". $authheader);

            $aHeadElems = explode(' ', $authheader);

            $this->token_type = $aHeadElems[0];
            $this->token  = $aHeadElems[1];
        }
    }

    // get new token issuer based on the current token
    public function getTokenIssuer($type) {
        return $this->model->getIssuer($type);
    }

    // get the token issuer for the current token
    public function getTokenModel() {
        return $this->model;
    }

    public function getTokenUser() {
        return $this->model->getUser();
    }

    public function getToken() {
        return $this->model->getToken();
    }

    public function getJWT() {
        return $this->jwt_token;
    }

    public function ignoreTokenTypes($typeList) {
        if (isset($typeList)) {
            if (!is_array($typeList)) {
                $typeList = array($typeList);
            }
            foreach ($typeList as $tokenType) {
                $k = array_search($tokenType);
                array_splice($this->acceptTypes, $k, 1);
            }
        }
    }

    public function acceptTokenTypes($typeList) {

        if (isset($typeList)) {
            if (!is_array($typeList)) {
                $typeList = array($typeList);
            }

            foreach ($typeList as $tokenType) {

                if (!in_array($tokenType, $this->accept_list)) {
                    $this->accept_list[] = $tokenType;
                }
            }
        }
    }

    public function resetAcceptedTokens($typeList){
        if (isset($typeList) && !empty($typeList)) {
            if (!is_array($typeList)) {
                $typeList = array($typeList);
            }

            $this->accept_list = $typeList;
        }
    }

    public function setAcceptedTokenTypes($typeList){
        if (isset($typeList) && !empty($typeList)) {
            if (!is_array($typeList)) {
                $typeList = array($typeList);
            }

            $this->accept_type = $typeList;
        }
    }

    public function requireUser() {
        if (!in_array("user_uuid", $this->requireUUID)) {
            $this->requireUUID[] = "user_uuid";
        }
    }

    public function requireService() {
        if (!in_array("service_uuid", $this->requireUUID)) {
            $this->requireUUID[] = "service_uuid";
        }
    }

    public function requireClient() {
        if (!in_array("client_id", $this->requireUUID)) {
            $this->requireUUID[] = "client_id";
        }
    }

    public function verifyRawToken($rawtoken) {
        if (isset($rawtoken) &&
            !empty($rawtoken) &&
            $rawtoken == $this->token) {

            return true;
        }
        return false;
    }

    public function verifyJWTClaim($claim, $value) {
        if (isset($value) &&
            !empty($value) &&
            isset($claim) &&
            !empty($claim) &&
            isset($this->jwt_token) &&
            $this->jwt_token->getClaim($claim) == $value) {

            return true;
        }

        return false;
    }

    protected function validate() {
        if (!isset($this->token_type) ||
            empty($this->token_type)) {

            // nothin to validate
            $this->log("no token type available");
            $this->log(json_encode(getallheaders()));
            return false;
        }

        if (!isset($this->token) ||
            empty($this->token)) {

            // no token to validate
            $this->log("no raw token available");
            return false;
        }

        $fname = "validate_" . strtolower($this->token_type);

        // make authorization scheme validation more flexible
        if (!method_exists($this, $fname)) {
            $this->log("authorization method not supported")
            return false;
        }

        if (!empty($this->accept_list) &&
            !in_array($this->token_type, $this->accept_list)) {

            // the script does not accept the provided token type;
            $this->log("token type not acecpted");

            return false;
        }

        // This will transform Bearer Tokens accordingly
        $this->extractToken();

        if (!isset($this->token_info["kid"]) ||
            empty($this->token_info["kid"])) {

            $this->log("no token id available");
            // no token id
            return false;
        }

        // verify that the token is in our token store
        $this->findToken();

        if (!isset($this->token_key)) {
            // token not found
            $this->log("no token available");
            return false;
        }

        if ($this->token_data["consumed"] > 0) {
            $this->log("token already consumed");
            return false;
        }

        if (!in_array($this->token_type, $this->accept_type)) {
            $this->log("not accepted token type. Given type '" . $this->token_type . "'");
            return false;
        }

        if ($this->token_data["expires"] > 0 &&
            $this->token_data["expires"] < time()) {

            // consume token
            $this->model->consumeToken();
            $this->log("token expired - consume it!");
            return false;
        }

        // eventually we want to run authorization specific validation
        if (!call_user_func(array($this, $fname))) {
            return false;
        }

        if (!isset($this->token_info["access_key"])) {
            $this->log("missing client secret");
            return false;
        }

        // at this point we have to increase the sequence
        if ($this->token_data["seq_nr"] > 0) {
            $this->model->sequenceStep();
        }

        // this logic should be part of token manager

        foreach ($this->requireUUID as $id) {
            if(!$this->model->hasTokenValue($id)) {
                $this->log("required referece is missing")
                return false;
            }
        }

        return true;
    }

    // auth type specific validation

    protected function validate_bearer() {
        // validate flat bearer is already complete by now
        return true;
    }

    protected function validate_jwt() {
        $alg = $this->jwt_token->getHeader("alg");

        if (!isset($alg) || empty($alg)) {
            $this->log("reject unprotected jwt");
            return false;
        }

        // enforce algorithm
        if (!$this->model->checkTokenValue("mac_algorithm", $alg)) {

            $this->log("invalid jwt sign method presented");
            $this->log("expected: '" . $this->token_data["mac_algorithm"] ."'");
            $this->log("received: '" . $alg."'");
            return false;
        }

        $signer = $this->model->getSigner();

        if (!isset($signer)) {
            $this->log("no jwt signer found for " . $alg);
            return false;
        }

        if(!$this->jwt_token->verify($signer, $this->token_data["mac_key"])) {

            $this->log("jwt signature does not match key '" . $this->token_data["mac_key"] . "'");
            $this->log("using signer '" . $signerClass. "'");
            $this->log("requested signer '" . $this->jwt_token->getHeader("alg") . "'");
            return false;
        }

        if ($this->jwt_token->getClaim("iss") != $this->token_data["client_id"]) {
            $this->log("jwt issuer does not match");
            $this->log("expected: " . $this->token_data["client_id"]);
            $this->log("expected: " . $this->jwt_token->getClaim("iss"));
            return false;
        }

        // ignore sub, aud, and name for the time being.

        return true;
    }

    protected function validate_mac() {
        if (!isset($this->token_info["mac"]) ||
            empty($this->token_info["mac"])) {

            // token is not signed ignore
            $this->log("token is not signed ignore");
            return false;
        }

        // check sequence
        if (!isset($this->token_info["ts"]) ||
            empty($this->token_info["ts"])) {

            // missing timestamp
            $this->log("missing timestamp");

            return false;
        }

        // verify implicit sequence, don't allow resuing the time
        if ($this->model->hasTokenValue("last_access") &&
            $this->token_info["ts"] <= $this->token_data["last_access"]) {

            $this->log("new request is older that previous one");
            $this->service->forbidden();
            return false;
        }

        $this->model->updateAccess();

        if ($this->model->hasTokenValue("seq_nr") &&
            (!isset($this->token_info["seq_nr"]) ||
            empty($this->token_info["seq_nr"]))) {

            // no sequence provided
            $this->log("missing seq_nr but requested");

            $this->model->consumeToken();
            return false;
        }

        if (!$this->manaer->checkTokenValue("seq_nr",
                                            $this->token_info["seq_nr"])) {
            // out of bounds
            $this->model->consumeToken();
            $this->log("token sequence out of bounds");

            return false;
        }

        if ($this->token_data["seq_nr"] == 1 &&
            (!isset($this->token_info["access_token"]) ||
             empty($this->token_info["access_token"]))) {

            if ($this->token_info["access_token"] != $this->token_key) {
                // invalid token during handshake
                $this->log("bad token handshake");

                return false;
            }
        }

        // at this point we have to increase the sequence
        if ($this->token_data["seq_nr"] > 0) {
            $this->model->sequenceStep();
            // $this->log("seq step");
        }

        // first line is METHOD REQPATH+GETPARAM PROTOCOL VERSION
        // protocol version is (HTTP/1.1 or HTTP/2.0)
        $payload =  $_SERVER['REQUEST_METHOD'] . " " .
                    $_SERVER['REQUEST_URI'] . " " .
                    $_SERVER['SERVER_PROTOCOL']. "\n";

        $payload .=  $this->token_info["ts"] ."\n";

        if (array_key_exists("h", $this->token_info)) {
            $aTokenHeaders  = explode(":");
            $aRequestHeader = getallheaders();

            foreach ($aTokenHeaders as $header) {
                switch($header) {
                    case "host":
                        $payload .= $_SERVER[HTTP_HOST] ."\n";
                        break;
                    case "client":
                        if (!empty($this->token_data["client_id"])) {
                            $payload .= $this->token_data["client_id"] ."\n";
                        }
                        break;
                    default:
                        if (array_key_exists($header, $aRequestHeader)) {
                            $payload .= $aRequestHeader[$header] . "\n";
                        }
                        else {
                            // according to RFC add NULL Content
                            $payload .= "\n";
                        }
                        break;
                }
            }
        }
        else {
            $payload .= $_SERVER["HTTP_HOST"] ."\n";
        }

        // NOTE: MAC appear to be replaced by JWTs. But we use the same algorithm
        $signer = $this->model->getSigner();

        if (!$signer->verify(base64_decode($this->token_info["mac"]),
                             $payload,
                             $this->token_data["mac_key"])) {

            // bad mac
            $this->log("mac mismatch ");
            $this->log("client token ". $this->token_info["mac"]);

            return false;
        }

        return true;
    }

    private function extractToken() {
        $this->token_info = array();

        if ($this->token_type == "MAC") {

            $aTokenItems = explode(',', $this->token);
            foreach ($aTokenItems as $item)
            {
                list($key, $value) = explode("=", $item, 2);
                $this->token_info[$key] = $value;
            }
        }
        else if ($this->token_type == "Bearer") {
            $jwt = new JWT\Parser();
            try {
                $token = $jwt->parse($this->token);
            }
            catch (InvalidArgumentException $e) {
                $this->log($e->getMessage());
            }
            catch (RuntimeException $e) {
                $this->log($e->getMessage());
            }

            if (isset($token)) {
                $this->token_info["kid"]  = $token->getHeader("kid");
                $this->token_type = "jwt";
                $this->jwt_token = $token;

                // $this->token_info["kid"] = $this->token;
            }
            else {
                $this->token_info["kid"] = $this->token;
            }
        }
        else if ($this->token_type == "Basic" ) {
            $this->token_type = null; // we need to find out about the token type

            $authstr = base64_decode($this->token);

            //$this->log('authstr ' . $authstr);

            $auth = explode(":", $authstr);

            $this->token_info["kid"]        = array_shift($auth);
            $this->token_info["access_key"] = array_shift($auth);

            //$this->log('kid: ' . $this->token_info["kid"] );
            //$this->log('access_key: ' . $this->token_info["access_key"] );
        }
    }

    private function findToken() {
        if ($this->model->findToken($this->token_info["kid"])) {

            $this->token_data = $this->model->next();

            if ($this->token_data) {
                $this->token_key = $this->token_data["access_key"];
                $this->token_type = $this->token_data["token_type"];
            }
        }
    }
}

?>