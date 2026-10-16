<?php

namespace WiliWeb\Cashback\Sms;

use WiliWeb\Cashback\Settings\Settings;

final class IppanelClient
{
    private array $settings;
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? Settings::get();
    }
    public static function phone(string $phone): string
    {
        $phone = preg_replace('/[\s()-]/', '', Settings::digits($phone));
        if (preg_match('/^09\d{9}$/D', $phone)) {
            $phone = '+98'.substr($phone, 1);
        } elseif (preg_match('/^989\d{9}$/D', $phone)) {
            $phone = '+'.$phone;
        } elseif (preg_match('/^00989\d{9}$/D', $phone)) {
            $phone = '+'.substr($phone, 2);
        }
        return preg_match('/^\+989\d{9}$/D', $phone) ? $phone : '';
    }
    public function send(array $message): array
    {
        $s = $this->settings;
        $phone = self::phone((string)($message['phone'] ?? ''));
        if (!$phone || !$s['sms_key'] || !preg_match('/^\+?\d{5,16}$/D', $s['sms_sender'])) {
            return ['state' => 'failed','provider_id' => null,'code' => 'configuration'];
        }
        $kind = $message['kind'] === 'reminder' ? 'reminder' : 'grant';
        $variables = (array)$message['variables'];
        $body = ['from_number' => $s['sms_sender']];
        if ($s['sms_mode'] === 'pattern') {
            $code = $s['pattern_'.$kind];
            if ($code === '') {
                return ['state' => 'failed','provider_id' => null,'code' => 'pattern_missing'];
            }
            $params = [];
            foreach (explode(',', $s['pattern_variables']) as $mapping) {
                $pair = explode('=', trim($mapping), 2);
                if (count($pair) !== 2 || !preg_match('/^[A-Za-z0-9_-]+$/D', $pair[0]) || !array_key_exists($pair[1], $variables)) {
                    return ['state' => 'failed','provider_id' => null,'code' => 'pattern_mapping'];
                }$params[$pair[0]] = (string)$variables[$pair[1]];
            }
            $body += ['sending_type' => 'pattern','code' => $code,'recipients' => [$phone],'params' => (object)$params];
        } else {
            $replace = [];
            foreach ($variables as $k => $v) {
                $replace['{'.$k.'}'] = (string)$v;
            }$body += ['sending_type' => 'webservice','message' => strtr($s['text_'.$kind], $replace),'params' => ['recipients' => [$phone]]];
        }
        $response = wp_remote_post('https://edge.ippanel.com/v1/api/send', ['headers' => ['Authorization' => $s['sms_key'],'Content-Type' => 'application/json'],'body' => wp_json_encode($body),'timeout' => 15,'redirection' => 0,'sslverify' => true]);
        if (is_wp_error($response)) {
            return ['state' => 'unknown','provider_id' => null,'code' => 'transport_unknown'];
        }
        $http = (int)wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($http >= 200 && $http < 300 && ($data['meta']['status'] ?? null) === true && !empty($data['data']['message_outbox_ids'][0])) {
            return ['state' => 'sent','provider_id' => (string)$data['data']['message_outbox_ids'][0],'code' => null];
        }
        if ($http >= 400 && $http < 500 && is_array($data) && ($data['meta']['status'] ?? null) === false) {
            return ['state' => 'failed','provider_id' => null,'code' => 'provider_'.$http];
        }
        return ['state' => 'unknown','provider_id' => null,'code' => 'response_unconfirmed'];
    }
}
