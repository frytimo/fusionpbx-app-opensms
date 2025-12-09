<?php

class modifier_url_decode implements opensms_message_modifier {
    public function __invoke(settings $settings, opensms_message $message): void {
        $message->to_number = urldecode($message->to_number);
        $message->from_number = urldecode($message->from_number);
        $message->sms = urldecode($message->sms);
    }

    public function priority(): int {
        return 5;
    }
}
