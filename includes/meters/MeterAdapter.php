<?php
declare(strict_types=1);

/**
 * 電錶抽象層:平台不認識品牌,只認這三個動作。
 *
 * readLatest()  取最新讀值(度)
 * readHistory() 取區間讀值(對帳/補抄用)
 * control()     儲值型電錶的斷/復電(僅支援的機型)
 *
 * 新增品牌 = 新增 class + 在 MeterService::adapter() 註冊;抄表、帳單、通知流程完全不用改。
 */
interface MeterAdapter
{
    public function code(): string;
    public function label(): string;
    public function isMock(): bool;

    /** @return array{ok:bool,message:string,data:array{reading?:int,read_at?:string,power?:bool}} */
    public function readLatest(array $device): array;

    /** @return array{ok:bool,message:string,data:array<int,array{reading:int,read_at:string}>} */
    public function readHistory(array $device, string $from, string $to): array;

    /** @return array{ok:bool,message:string,data:array} */
    public function control(array $device, bool $powerOn): array;
}
