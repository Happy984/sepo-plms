<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

/**
 * A hosted or push payment gateway.
 *
 * start() begins a payment for an enrolment and returns where to send the
 * learner (or nothing, for a push to their phone). check() asks the gateway
 * — never the browser or a webhook body — whether that payment went through.
 */
interface Gateway
{
    public function ready(): bool;

    /**
     * @param array<string,mixed> $enrollment
     * @param array<string,mixed> $method
     * @return array{url:string,ref:string,status:string,poll:bool,message:string}
     */
    public function start(array $enrollment, array $method, string $phone): array;

    /**
     * @param array<string,mixed> $enrollment
     * @return array{state:string,status:string} state is paid, pending or failed
     */
    public function check(array $enrollment): array;
}
