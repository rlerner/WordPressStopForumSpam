<?php
set_time_limit(900);
require_once "wp-config.php";

class WPStopForumSpam {
	private $con;
	private $tablePrefix = "wp_";
	private $apiKey;
	private $earMark;

	public function __construct(string $tablePrefix,string $apiKey) {
		$this->con = new MySQLi(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
		$this->tablePrefix = $tablePrefix;
		$this->apiKey = $apiKey;
		$this->earMark = $this->con->real_escape_string("[".substr(md5($this->apiKey),0,5)."]");
	}

	/**
	 * Grabs all comments that have been flagged as spam, except those that have been earmarked as already sent.
	 *
	 * Earmarking prepends the first five characters of your API key to the comment content so that it is not transmitted multiple times.
	 * It uses the API Key to generate this code to make it less predictable to spammers. The earmark is also MD5'd so that, if the code is exposed, it doesn't compromise your API key.
	*/
	public function getSpam() {
		$query = "
			SELECT
				C.comment_ID
				,C.comment_author
				,C.comment_author_IP
				,C.comment_author_email
				,C.comment_content
			FROM " . DB_NAME . ".{$this->tablePrefix}comments C
			WHERE
				C.comment_approved = 'spam'
				AND LEFT(C.comment_content,7) != '" . $this->earMark . "'
				";
		$result = $this->con->query($query) or trigger_error($this->con->error,E_USER_ERROR);
		while ($row = $result->fetch_assoc()) {
			$spam[] = $row;
		}
		return $spam;
	}

	public function earMark(int $commentID) {
		$query = "UPDATE " . DB_NAME . ".{$this->tablePrefix}comments SET comment_content = CONCAT('{$this->earMark}',comment_content) WHERE comment_ID = '" . $this->con->real_escape_string($commentID) . "' LIMIT 1";
		$this->con->query($query) or trigger_error($this->con->error,E_USER_ERROR);
		return true;
	}

	public function reportSpam() {
		$spam = $this->getSpam();

		foreach ($spam as $k=>$v) {
			print_r($v);

			$username = utf8_encode(urlencode($v["comment_author"]));
			$ip = utf8_encode(urlencode($v["comment_author_IP"]));
			$evidence = utf8_encode(urlencode($v["comment_content"]));
			$email = utf8_encode(urlencode($v["comment_author_email"]));

			$ch = curl_init("https://www.stopforumspam.com/add");
			if ($ch) {
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, "username={$username}&ip_addr={$ip}&evidence={$evidence}&email={$email}&api_key={$this->apiKey}");
				$response = curl_exec($ch);
				$code = curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
				curl_close($ch);

				if ($code!=200) {
					die("Received an error submitting spam");
				}
				$this->earMark($v["comment_ID"]);

			} else {
				die("Could not connect to StopForumSpam");
			}
			sleep(1); // Tarpit requests
		}
	}

}



$apiKey = "TODO";
$x = new WPStopForumSpam($table_prefix,$apiKey);
$x->reportSpam();

echo "done";
