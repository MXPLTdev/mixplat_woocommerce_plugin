<?php

class MixplatLib
{
	const VERSION = "1.0.0";
	const URL     = "https://api.mixplat.com/";

	public static function calcPaymentSignature($data, $key)
	{
		return md5($data['request_id'] . $data['project_id'] . $data['merchant_payment_id'] . $key);
	}

	public static function calcActionSignature($data, $key)
	{
		return md5($data['payment_id'] . $key);
	}

	public static function getIdempotenceKey()
	{
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	public static function request($method, $data)
	{
		$data = array_merge(['api_version' => 3], $data);
		$url  = self::URL . $method;
		$ch   = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_USERAGENT, 'MixplatLib ' . self::VERSION);
		$response = curl_exec($ch);
		$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if ($code != 200) {
			throw new MixplatException("Response code: $code, $error, $response");
		}
		$result = json_decode($response);
		if (!$result) {
			throw new MixplatException("Response decoding error. $response");
		}
		if ($result->result != "ok") {
			throw new MixplatException($result->error_description);
		}
		return $result;
	}

	public static function createPayment($data)
	{
		return self::request('create_payment_form', $data);
	}

	public static function refundPayment($data)
	{
		return self::request('refund_payment', $data);
	}

	public static function cancelPayment($data)
	{
		return self::request('cancel_payment', $data);
	}

	public static function confirmPayment($data)
	{
		return self::request('confirm_payment', $data);
	}

	public static function getPaymentStatus($data)
	{
		return self::request('get_payment_status', $data);
	}

	public static function normalizeReceiptItems($items, $total)
	{
		$result    = array();
		$realTotal = 0;
		foreach ($items as $item) {
			$realTotal += $item['sum'];
		}
		if (abs($realTotal - $total) > 0.0001) {
			$subtotal = 0;
			$coef     = $total / $realTotal;
			$lastItem = count($items) - 1;
			foreach ($items as $id => $item) {
				if ($id == $lastItem) {
					$sum         = $total - $subtotal;
					$item['sum'] = $sum;
					$result[]    = $item;
				} else {
					$sum         = intval(round($item['sum'] * $coef));
					$item['sum'] = $sum;
					$subtotal += $sum;
					$result[] = $item;
				}
			}
		} else {
			$result = $items;
		}
		return $result;
	}
}

class MixplatException extends Exception {}
