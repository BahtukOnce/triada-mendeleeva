package ru.triadamendeleeva.app;

import android.app.Activity;
import android.app.Dialog;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AlertDialog;
import androidx.core.content.FileProvider;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * Проверка обновлений и установка нового APK «внутри приложения».
 *
 * Приложение раздаётся файлом (не через Google Play), поэтому обновляемся сами:
 *   1. GET {@link #VERSION_URL} → JSON {versionCode, versionName, url, notes}.
 *   2. Если versionCode на сервере больше установленного — показываем диалог.
 *   3. По кнопке «Обновить» качаем APK ПРЯМО В ПРИЛОЖЕНИИ, показывая фирменный
 *      полноэкранный экран «Пожалуйста, подождите» с логотипом и прогрессом,
 *      затем открываем системный установщик (одна подпись у всех сборок — build.gradle).
 *
 * Всё тихо: любая сетевая ошибка при проверке = «обновлений нет», без всплывашек.
 */
public class UpdateChecker {

    private static final String VERSION_URL = "https://triada-mendeleeva.ru/app/version.php";
    private static final String APK_MIME = "application/vnd.android.package-archive";
    private static final String APK_NAME = "triada-update.apk";

    /** Запустить проверку через небольшую задержку после старта. */
    public static void check(final Activity activity) {
        new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
            @Override public void run() { runCheck(activity); }
        }, 2500);
    }

    private static void runCheck(final Activity activity) {
        new Thread(new Runnable() {
            @Override public void run() {
                try {
                    JSONObject info = fetchJson(VERSION_URL);
                    if (info == null) return;
                    int latest = info.optInt("versionCode", 0);
                    if (latest <= currentVersionCode(activity)) return;

                    final String url = info.optString("url", "");
                    final String name = info.optString("versionName", "");
                    final String notes = info.optString("notes", "");
                    if (url.isEmpty()) return;

                    activity.runOnUiThread(new Runnable() {
                        @Override public void run() { showDialog(activity, name, notes, url); }
                    });
                } catch (Throwable ignored) {
                    // нет сети / кривой ответ — молча выходим
                }
            }
        }).start();
    }

    private static void showDialog(final Activity activity, String name, String notes, final String url) {
        if (activity.isFinishing()) return;
        StringBuilder msg = new StringBuilder();
        if (!name.isEmpty()) msg.append("Версия ").append(name).append("\n\n");
        msg.append(notes.isEmpty() ? "Доступна новая версия приложения." : notes);

        new AlertDialog.Builder(activity)
                .setTitle("Доступно обновление")
                .setMessage(msg.toString())
                .setCancelable(true)
                .setPositiveButton("Обновить", (d, w) -> startUpdate(activity, url))
                .setNegativeButton("Позже", null)
                .show();
    }

    private static void startUpdate(final Activity activity, String url) {
        // Разрешение «установка неизвестных приложений» (Android 8+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                && !activity.getPackageManager().canRequestPackageInstalls()) {
            Toast.makeText(activity,
                    "Разрешите установку из этого приложения и нажмите «Обновить» ещё раз",
                    Toast.LENGTH_LONG).show();
            try {
                Intent i = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:" + activity.getPackageName()));
                activity.startActivity(i);
            } catch (Throwable ignored) {}
            return;
        }
        downloadBranded(activity, url);
    }

    /** Фирменный полноэкранный экран загрузки + скачивание APK внутри приложения. */
    private static void downloadBranded(final Activity activity, final String url) {
        final float dp = activity.getResources().getDisplayMetrics().density;

        LinearLayout root = new LinearLayout(activity);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER);
        root.setBackgroundColor(Color.parseColor("#0e0e11"));
        int pad = Math.round(28 * dp);
        root.setPadding(pad, pad, pad, pad);

        ImageView logo = new ImageView(activity);
        try {
            logo.setImageResource(R.drawable.update_logo);
        } catch (Throwable t) {
            try { logo.setImageDrawable(activity.getPackageManager().getApplicationIcon(activity.getPackageName())); }
            catch (Throwable ignored) {}
        }
        LinearLayout.LayoutParams logoLp =
                new LinearLayout.LayoutParams(Math.round(116 * dp), Math.round(158 * dp));
        logoLp.bottomMargin = Math.round(24 * dp);
        root.addView(logo, logoLp);

        TextView wait = new TextView(activity);
        wait.setText("Пожалуйста, подождите");
        wait.setTextColor(Color.WHITE);
        wait.setTextSize(18);
        wait.setGravity(Gravity.CENTER);
        root.addView(wait);

        TextView sub = new TextView(activity);
        sub.setText("Загрузка обновления…");
        sub.setTextColor(Color.parseColor("#9a9aa2"));
        sub.setTextSize(13);
        sub.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams subLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        subLp.topMargin = Math.round(8 * dp);
        subLp.bottomMargin = Math.round(20 * dp);
        root.addView(sub, subLp);

        final ProgressBar prog = new ProgressBar(activity, null, android.R.attr.progressBarStyleHorizontal);
        prog.setMax(100);
        prog.setIndeterminate(true);
        LinearLayout.LayoutParams progLp = new LinearLayout.LayoutParams(
                Math.round(220 * dp), ViewGroup.LayoutParams.WRAP_CONTENT);
        root.addView(prog, progLp);

        final TextView pct = new TextView(activity);
        pct.setTextColor(Color.parseColor("#9a9aa2"));
        pct.setTextSize(12);
        pct.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams pctLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        pctLp.topMargin = Math.round(10 * dp);
        root.addView(pct, pctLp);

        final Dialog dlg = new Dialog(activity, android.R.style.Theme_Black_NoTitleBar_Fullscreen);
        dlg.setContentView(root);
        dlg.setCancelable(false);
        if (dlg.getWindow() != null) {
            dlg.getWindow().setBackgroundDrawable(new ColorDrawable(Color.parseColor("#0e0e11")));
        }
        try { dlg.show(); } catch (Throwable ignored) {}

        new Thread(new Runnable() {
            @Override public void run() {
                try {
                    File dir = new File(activity.getExternalFilesDir(null), "updates");
                    if (!dir.exists()) dir.mkdirs();
                    final File apk = new File(dir, APK_NAME);
                    if (apk.exists()) apk.delete();

                    HttpURLConnection c = openFollowing(url);
                    final int total = c.getContentLength();
                    InputStream in = c.getInputStream();
                    OutputStream out = new FileOutputStream(apk);
                    byte[] buf = new byte[16384];
                    int n, done = 0;
                    long lastUi = 0;
                    while ((n = in.read(buf)) != -1) {
                        out.write(buf, 0, n);
                        done += n;
                        final int d = done;
                        long now = System.currentTimeMillis();
                        if (now - lastUi > 80 || (total > 0 && d >= total)) {
                            lastUi = now;
                            activity.runOnUiThread(new Runnable() {
                                @Override public void run() {
                                    if (total > 0) {
                                        int p = (int) (100L * d / total);
                                        prog.setIndeterminate(false);
                                        prog.setProgress(p);
                                        pct.setText(p + " %");
                                    } else {
                                        pct.setText((d / 1024) + " КБ");
                                    }
                                }
                            });
                        }
                    }
                    out.flush(); out.close(); in.close(); c.disconnect();

                    activity.runOnUiThread(new Runnable() {
                        @Override public void run() {
                            try { dlg.dismiss(); } catch (Throwable ignored) {}
                            install(activity, apk);
                        }
                    });
                } catch (Throwable t) {
                    activity.runOnUiThread(new Runnable() {
                        @Override public void run() {
                            try { dlg.dismiss(); } catch (Throwable ignored) {}
                            fallback(activity, url);
                        }
                    });
                }
            }
        }).start();
    }

    /** GET с ручным следованием редиректам (GitHub Releases редиректит на CDN). */
    private static HttpURLConnection openFollowing(String url) throws Exception {
        String cur = url;
        for (int i = 0; i < 6; i++) {
            HttpURLConnection c = (HttpURLConnection) new URL(cur).openConnection();
            c.setInstanceFollowRedirects(false);
            c.setConnectTimeout(15000);
            c.setReadTimeout(20000);
            c.setRequestProperty("User-Agent", "TriadaApp");
            int code = c.getResponseCode();
            if (code == HttpURLConnection.HTTP_MOVED_PERM || code == HttpURLConnection.HTTP_MOVED_TEMP
                    || code == HttpURLConnection.HTTP_SEE_OTHER || code == 307 || code == 308) {
                String loc = c.getHeaderField("Location");
                c.disconnect();
                if (loc == null) throw new Exception("no redirect location");
                cur = loc;
                continue;
            }
            if (code != 200) { c.disconnect(); throw new Exception("http " + code); }
            return c;
        }
        throw new Exception("too many redirects");
    }

    /** Не удалось скачать в приложении — предложить открыть страницу в браузере. */
    private static void fallback(final Activity activity, final String url) {
        if (activity.isFinishing()) return;
        try {
            new AlertDialog.Builder(activity)
                    .setTitle("Не удалось скачать")
                    .setMessage("Открыть страницу загрузки в браузере?")
                    .setPositiveButton("Открыть", (d, w) -> {
                        try { activity.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); }
                        catch (Throwable ignored) {}
                    })
                    .setNegativeButton("Отмена", null)
                    .show();
        } catch (Throwable t) {
            Toast.makeText(activity, "Не удалось скачать обновление", Toast.LENGTH_LONG).show();
        }
    }

    private static void install(Activity activity, File apk) {
        try {
            if (apk == null || !apk.exists() || apk.length() == 0) {
                Toast.makeText(activity, "Файл обновления не загрузился", Toast.LENGTH_LONG).show();
                return;
            }
            Uri uri = FileProvider.getUriForFile(activity,
                    activity.getPackageName() + ".fileprovider", apk);
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(uri, APK_MIME);
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            activity.startActivity(i);
        } catch (Throwable t) {
            Toast.makeText(activity, "Не удалось открыть установщик", Toast.LENGTH_LONG).show();
        }
    }

    private static int currentVersionCode(Context ctx) {
        try {
            return ctx.getPackageManager().getPackageInfo(ctx.getPackageName(), 0).versionCode;
        } catch (Throwable t) {
            return Integer.MAX_VALUE; // не знаем версию — считаем, что обновлять не надо
        }
    }

    private static JSONObject fetchJson(String urlStr) {
        HttpURLConnection c = null;
        try {
            URL url = new URL(urlStr);
            c = (HttpURLConnection) url.openConnection();
            c.setConnectTimeout(8000);
            c.setReadTimeout(8000);
            c.setRequestProperty("Accept", "application/json");
            c.setRequestProperty("User-Agent", "TriadaApp");
            if (c.getResponseCode() != 200) return null;
            StringBuilder sb = new StringBuilder();
            BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), "UTF-8"));
            String line;
            while ((line = r.readLine()) != null) sb.append(line);
            r.close();
            String body = sb.toString().trim();
            if (body.isEmpty()) return null;
            return new JSONObject(body);
        } catch (Throwable t) {
            return null;
        } finally {
            if (c != null) c.disconnect();
        }
    }
}
