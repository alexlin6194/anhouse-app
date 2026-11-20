<?php
declare(strict_types=1);

/**
 * 智慧裝置抽象層 —— 平台只認這個介面,不認品牌。
 *
 * 為什麼要抽象層:
 *   門鎖/電錶品牌幾十家(Aqara、Yale、Samsung、台科電…),各自 API 不同、
 *   合約與憑證也不同。若把某家的 API 直接寫進租約/發碼流程,換一家就要重寫。
 *   Phase 1 先把「平台要什麼」定義清楚,品牌端只是可替換的實作。
 *
 * 新增一個品牌 = 新增一個 class 實作本介面 + 在 DeviceService::adapter() 註冊,
 * 其餘流程(簽約發碼、房客改碼、退租撤權)完全不用改。
 */
interface DeviceAdapter
{
    /** 介面識別碼(寫在 devices.adapter 欄位) */
    public function code(): string;

    /** 顯示名稱(APP 上用) */
    public function label(): string;

    /** 是否為模擬/示範用(不串真實硬體) */
    public function isMock(): bool;

    /**
     * 在鎖端建立/更新一組密碼。
     *
     * @param array  $device     devices 資料列
     * @param string $slotKey    憑證槽(gate / door / tenant / manager / vendor)
     * @param string $pin        6 位數字密碼(僅在此時以明文傳入,Adapter 負責下發)
     * @param string $validFrom  Y-m-d H:i:s
     * @param ?string $validTo   Y-m-d H:i:s;null = 永久(直到撤銷)
     * @return array{ok:bool, message:string}
     */
    public function setPin(array $device, string $slotKey, string $pin, string $validFrom, ?string $validTo): array;

    /**
     * 撤銷某槽位的密碼(退租、換房客、管理員收回)。
     *
     * @return array{ok:bool, message:string}
     */
    public function revoke(array $device, string $slotKey): array;

    /**
     * 讀取裝置狀態(電量、連線、鎖舌狀態…);無法取得時回 ok=false。
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function status(array $device): array;

    /**
     * 驗證某組密碼在指定時間是否可開鎖 —— 模擬器用來讓你「真的試開一次」。
     * 正式品牌多半只回「開鎖成功/失敗」,不回密碼本身。
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function verifyPin(array $device, string $slotKey, string $pin, ?string $at = null): array;
}
