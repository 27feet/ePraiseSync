<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\BehaviourEmailTemplate;

class BehaviourNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array  $context  Variables for token replacement:
     *   - student_name, category, value, threshold, window_start, window_end, staff_or_parent, etc.
     * @param  string $templateKey  e.g. 'uniform_5'
     */
    public function __construct(
        public array $context,
        public string $templateKey
    ) {}

    public function build(): self
    {
        // 1) Try DB template by key
        $tpl = BehaviourEmailTemplate::query()
            ->where('key', $this->templateKey)
            ->where('is_active', true)
            ->first();

        if ($tpl) {
            $subject = $this->tokenReplace($tpl->subject, $this->context);
            $html    = $this->tokenReplace($tpl->body_html, $this->context);
            $text    = $tpl->body_text ? $this->tokenReplace($tpl->body_text, $this->context) : null;

            $m = $this->subject($subject)->html($html);
            if ($text) {
                $m->text('emails.behaviour.plain-fallback', ['text' => $text]);
            }
            return $m;
        }

        // 2) Fallback to a simple Blade view if no DB template found
        $subject = $this->tokenReplace(
            $this->context['fallback_subject'] ?? 'Behaviour notification – {{ student_name }}',
            $this->context
        );

        return $this->subject($subject)
            ->view('emails.behaviour.default', $this->context);
    }

    /**
     * Simple {{ token }} replacement in strings.
     */
    private function tokenReplace(string $str, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{{ ' . $k . ' }}'] = is_array($v) ? json_encode($v) : (string) $v;
        }
        return strtr($str, $map);
    }
}
