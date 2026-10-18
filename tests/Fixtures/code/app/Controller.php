<?php

declare(strict_types=1);

class Controller
{
    public function index(string $state): array
    {
        return [
            __('auth.failed'),
            trans('auth.throttle', ['seconds' => 5]),
            trans_choice('admin/users.count', 3),
            __("status.{$state}"),
            __('Save changes'),
            __('auth.not_there_yet'),
            $this->t('ignored.method'),
            format('ignored.function'),
        ];
    }
}
