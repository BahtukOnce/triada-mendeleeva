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
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

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

    // ── Фирменные цвета (те же, что у сайта: --bg/--sf/--bd/--ac/--tx/--tx2) ──
    private static final int C_BG     = Color.parseColor("#0e0e11");
    private static final int C_CARD   = Color.parseColor("#17171d");
    private static final int C_BORDER = Color.parseColor("#2e2e38");
    private static final int C_ACCENT = Color.parseColor("#e8332a");
    private static final int C_TX     = Color.parseColor("#ececed");
    private static final int C_TX2    = Color.parseColor("#9a9aa2");

    /** Диалог «Доступно обновление» в фирменном стиле (вместо системного серого). */
    private static void showDialog(final Activity activity, String name, String notes, final String url) {
        if (activity.isFinishing()) return;
        final float dp = activity.getResources().getDisplayMetrics().density;

        LinearLayout card = new LinearLayout(activity);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setBackground(roundedCard(dp));
        int pad = Math.round(24 * dp);
        card.setPadding(pad, Math.round(26 * dp), pad, Math.round(18 * dp));

        // Логотип клуба
        ImageView logo = new ImageView(activity);
        try {
            logo.setImageResource(R.drawable.update_logo);
        } catch (Throwable t) {
            try { logo.setImageDrawable(activity.getPackageManager().getApplicationIcon(activity.getPackageName())); }
            catch (Throwable ignored) {}
        }
        LinearLayout.LayoutParams logoLp =
                new LinearLayout.LayoutParams(Math.round(62 * dp), Math.round(84 * dp));
        logoLp.gravity = Gravity.CENTER_HORIZONTAL;
        logoLp.bottomMargin = Math.round(14 * dp);
        card.addView(logo, logoLp);

        TextView title = new TextView(activity);
        title.setText("Доступно обновление");
        title.setTextColor(C_TX);
        title.setTextSize(20);
        title.setGravity(Gravity.CENTER);
        title.setTypeface(title.getTypeface(), android.graphics.Typeface.BOLD);
        card.addView(title, wrap(Gravity.CENTER_HORIZONTAL, 0, 0, dp));

        if (!name.isEmpty()) {
            TextView ver = new TextView(activity);
            ver.setText("Версия " + name);
            ver.setTextColor(C_ACCENT);
            ver.setTextSize(13.5f);
            ver.setGravity(Gravity.CENTER);
            card.addView(ver, wrap(Gravity.CENTER_HORIZONTAL, 5, 0, dp));
        }

        // Разделитель
        View div = new View(activity);
        div.setBackgroundColor(C_BORDER);
        LinearLayout.LayoutParams divLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, Math.max(1, Math.round(dp)));
        divLp.topMargin = Math.round(16 * dp);
        divLp.bottomMargin = Math.round(14 * dp);
        card.addView(div, divLp);

        TextView body = new TextView(activity);
        body.setText(notes.isEmpty() ? "Доступна новая версия приложения." : notes);
        body.setTextColor(C_TX2);
        body.setTextSize(14.5f);
        body.setLineSpacing(Math.round(4 * dp), 1f);
        card.addView(body, wrap(Gravity.START, 0, 0, dp));

        // Кнопки: «Позже» призрачная, «Обновить» — красная
        LinearLayout row = new LinearLayout(activity);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.END);
        LinearLayout.LayoutParams rowLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        rowLp.topMargin = Math.round(20 * dp);
        card.addView(row, rowLp);

        final Dialog dlg = new Dialog(activity);
        dlg.requestWindowFeature(android.view.Window.FEATURE_NO_TITLE);

        TextView later = brandButton(activity, dp, "Позже", false);
        later.setOnClickListener(v -> { try { dlg.dismiss(); } catch (Throwable ignored) {} });
        row.addView(later);

        TextView upd = brandButton(activity, dp, "Обновить", true);
        upd.setOnClickListener(v -> {
            try { dlg.dismiss(); } catch (Throwable ignored) {}
            startUpdate(activity, url);
        });
        LinearLayout.LayoutParams updLp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        updLp.leftMargin = Math.round(10 * dp);
        row.addView(upd, updLp);

        dlg.setContentView(card);
        dlg.setCancelable(true);
        dlg.setCanceledOnTouchOutside(true);
        styleWindow(dlg, activity, dp);
        try { dlg.show(); } catch (Throwable ignored) {}
    }

    /** Карточка диалога: тёмный фон, скругление, тонкая рамка. */
    private static android.graphics.drawable.GradientDrawable roundedCard(float dp) {
        android.graphics.drawable.GradientDrawable g = new android.graphics.drawable.GradientDrawable();
        g.setColor(C_CARD);
        g.setCornerRadius(18 * dp);
        g.setStroke(Math.max(1, Math.round(dp)), C_BORDER);
        return g;
    }

    /** Кнопка в стиле сайта: primary — красная заливка, иначе призрачная с рамкой. */
    private static TextView brandButton(Activity activity, float dp, String text, boolean primary) {
        TextView b = new TextView(activity);
        b.setText(text);
        b.setTextSize(14.5f);
        b.setGravity(Gravity.CENTER);
        b.setTypeface(b.getTypeface(), android.graphics.Typeface.BOLD);
        b.setTextColor(primary ? Color.WHITE : C_TX2);
        int px = Math.round(20 * dp), py = Math.round(11 * dp);
        b.setPadding(px, py, px, py);
        android.graphics.drawable.GradientDrawable g = new android.graphics.drawable.GradientDrawable();
        g.setCornerRadius(11 * dp);
        if (primary) {
            g.setColor(C_ACCENT);
        } else {
            g.setColor(Color.TRANSPARENT);
            g.setStroke(Math.max(1, Math.round(dp)), C_BORDER);
        }
        b.setBackground(g);
        b.setClickable(true);
        b.setFocusable(true);
        return b;
    }

    /** Прозрачное окно с затемнением + ширина карточки под экран (макс. 360dp). */
    private static void styleWindow(Dialog dlg, Activity activity, float dp) {
        android.view.Window w = dlg.getWindow();
        if (w == null) return;
        w.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
        w.setDimAmount(0.78f);
        int screen = activity.getResources().getDisplayMetrics().widthPixels;
        int want = Math.min(screen - Math.round(48 * dp), Math.round(360 * dp));
        w.setLayout(want, ViewGroup.LayoutParams.WRAP_CONTENT);
    }

    private static LinearLayout.LayoutParams wrap(int gravity, int topDp, int bottomDp, float dp) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.gravity = gravity;
        lp.topMargin = Math.round(topDp * dp);
        lp.bottomMargin = Math.round(bottomDp * dp);
        return lp;
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
        root.setBackgroundColor(C_BG);
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
        wait.setTextColor(C_TX);
        wait.setTextSize(18);
        wait.setGravity(Gravity.CENTER);
        root.addView(wait);

        TextView sub = new TextView(activity);
        sub.setText("Загрузка обновления…");
        sub.setTextColor(C_TX2);
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
        // Полоса прогресса — фирменным красным, а не системным бирюзовым
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            prog.setProgressTintList(android.content.res.ColorStateList.valueOf(C_ACCENT));
            prog.setIndeterminateTintList(android.content.res.ColorStateList.valueOf(C_ACCENT));
            prog.setProgressBackgroundTintList(android.content.res.ColorStateList.valueOf(C_BORDER));
        }
        LinearLayout.LayoutParams progLp = new LinearLayout.LayoutParams(
                Math.round(220 * dp), ViewGroup.LayoutParams.WRAP_CONTENT);
        root.addView(prog, progLp);

        final TextView pct = new TextView(activity);
        pct.setTextColor(C_TX2);
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
            dlg.getWindow().setBackgroundDrawable(new ColorDrawable(C_BG));
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
            final float dp = activity.getResources().getDisplayMetrics().density;
            LinearLayout card = new LinearLayout(activity);
            card.setOrientation(LinearLayout.VERTICAL);
            card.setBackground(roundedCard(dp));
            int pad = Math.round(24 * dp);
            card.setPadding(pad, Math.round(22 * dp), pad, Math.round(18 * dp));

            TextView title = new TextView(activity);
            title.setText("Не удалось скачать");
            title.setTextColor(C_TX);
            title.setTextSize(18.5f);
            title.setGravity(Gravity.CENTER);
            title.setTypeface(title.getTypeface(), android.graphics.Typeface.BOLD);
            card.addView(title, wrap(Gravity.CENTER_HORIZONTAL, 0, 0, dp));

            TextView body = new TextView(activity);
            body.setText("Открыть страницу загрузки в браузере?");
            body.setTextColor(C_TX2);
            body.setTextSize(14.5f);
            body.setGravity(Gravity.CENTER);
            card.addView(body, wrap(Gravity.CENTER_HORIZONTAL, 10, 0, dp));

            LinearLayout row = new LinearLayout(activity);
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(Gravity.END);
            LinearLayout.LayoutParams rowLp = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            rowLp.topMargin = Math.round(20 * dp);
            card.addView(row, rowLp);

            final Dialog dlg = new Dialog(activity);
            dlg.requestWindowFeature(android.view.Window.FEATURE_NO_TITLE);

            TextView cancel = brandButton(activity, dp, "Отмена", false);
            cancel.setOnClickListener(v -> { try { dlg.dismiss(); } catch (Throwable ignored) {} });
            row.addView(cancel);

            TextView open = brandButton(activity, dp, "Открыть", true);
            open.setOnClickListener(v -> {
                try { dlg.dismiss(); } catch (Throwable ignored) {}
                try { activity.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); }
                catch (Throwable ignored) {}
            });
            LinearLayout.LayoutParams openLp = new LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            openLp.leftMargin = Math.round(10 * dp);
            row.addView(open, openLp);

            dlg.setContentView(card);
            dlg.setCancelable(true);
            styleWindow(dlg, activity, dp);
            dlg.show();
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
