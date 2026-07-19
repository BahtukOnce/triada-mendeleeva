# Мобильное приложение «Триада Менделеева» (Android)

Нативная обёртка (Capacitor) вокруг живого сайта **triada-mendeleeva.ru**.
Приложение показывает тот же сайт в полноэкранном режиме — со своей иконкой,
сплэш-экраном и без адресной строки. Весь контент и логика остаются на сайте,
поэтому **отдельно приложение обновлять не нужно** — что на сайте, то и в апке.

Приоритеты, под которые сделано: иконка/ярлык как у приложения, быстрый
полноэкранный запуск, задел под push-уведомления.

## Как получить APK (без установки Android Studio)

APK собирается в **GitHub Actions**:

1. Открой на GitHub вкладку **Actions** → workflow **«Сборка Android APK»**.
2. Нажми **Run workflow** (ветка `claude/mendeleev-table-website-yh0f51`) —
   или просто дождись сборки, она запускается сама при изменениях в `mobile/`.
3. Когда сборка позеленеет — внизу страницы запуска, в разделе **Artifacts**,
   скачай `triada-mendeleeva-apk` (внутри `triada-mendeleeva.apk`).
4. Перекинь файл на телефон и открой. При установке из файла Android попросит
   разрешить «установку из этого источника» — это нормально для APK вне Google Play.

> Это **debug**-сборка (self-signed) — её достаточно для установки и раздачи
> внутри клуба. Для публикации в Google Play нужна release-подпись (см. ниже).

## Сборка локально (если есть Android SDK)

```bash
cd mobile
npm ci
npx cap sync android
cd android
./gradlew assembleDebug
# APK: android/app/build/outputs/apk/debug/app-debug.apk
```

Нужны: Node 18+, JDK 17, Android SDK (platform 34, build-tools 34).
Иконки/сплэш генерируются скриптом `tools/gen_icons.py` (Python + Pillow) из
`public_html/assets/img/logo.png`; перезапускать нужно только если поменялось лого.

## Что настроено

- **appId:** `ru.triadamendeleeva.app`, имя «Триада Менделеева».
- Загружает `https://triada-mendeleeva.ru`; переходы внутри домена — в приложении,
  внешние ссылки (Telegram, VK, tel:) открываются в системном браузере/приложении.
- Тёмная тема (#0e0e11), иконка и сплэш с белым лого клуба.
- Плагины: App (кнопка «назад»), StatusBar, SplashScreen, Browser, PushNotifications.

## Push-уведомления (следующий шаг, нужен один твой шаг)

Плагин `@capacitor/push-notifications` уже подключён, но нативный push на Android
работает через Firebase Cloud Messaging (FCM), поэтому нужен бесплатный проект
Firebase:

1. https://console.firebase.google.com → создать проект → добавить Android-приложение
   с package `ru.triadamendeleeva.app`.
2. Скачать `google-services.json` и положить в `mobile/android/app/`
   (файл в `.gitignore` — в репозиторий не попадёт).
3. Подключить Google Services Gradle-плагин:
   - в `android/build.gradle` (buildscript → dependencies):
     `classpath 'com.google.gms:google-services:4.4.2'`
   - в конце `android/app/build.gradle`:
     `apply plugin: 'com.google.gms.google-services'`
   Плагин `@capacitor/push-notifications` сам подтянет `firebase-messaging`.
4. В приложении — запросить разрешение и зарегистрировать токен через
   `PushNotifications.register()` (Capacitor); токен отправлять на сайт.
5. На стороне сайта — рассылка через FCM HTTP v1 там, где сайт уже шлёт
   уведомления (таблица `notifications`) и в Telegram. Отдельная задача.

До этого приложение полностью рабочее — просто без пушей (у клуба уже есть
уведомления в Telegram-боте).

## Release-сборка для Google Play (если понадобится)

1. Создать keystore: `keytool -genkey -v -keystore triada.jks -alias triada -keyalg RSA -keysize 2048 -validity 10000`.
2. Прописать подпись в `android/app/build.gradle` (`signingConfigs`) через
   `keystore.properties` (в `.gitignore`).
3. `./gradlew bundleRelease` → `app-release.aab` для загрузки в Play Console.
