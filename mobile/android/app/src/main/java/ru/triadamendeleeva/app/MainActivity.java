package ru.triadamendeleeva.app;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Проверка обновлений «внутри приложения» (см. UpdateChecker)
        UpdateChecker.check(this);
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Вернулись из настроек, где выдавали разрешение на установку — продолжаем
        // прерванное обновление, чтобы не перезапускать приложение вручную.
        UpdateChecker.resumePending(this);
    }
}
