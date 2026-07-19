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
}
