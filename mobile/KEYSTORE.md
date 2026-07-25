# Ключ подписи приложения

APK подписывается одним и тем же ключом — иначе Android не даст поставить
обновление поверх установленной версии. Ключ **не хранится в репозитории**:
репозиторий публичный, а по ключу любой смог бы собрать APK, который телефон
примет как обновление настоящего приложения.

CI берёт ключ из **GitHub Secrets** и восстанавливает его только внутри
одноразового раннера (шаг «Ключ подписи из Secrets» в обоих workflow).

## Нужные секреты

`Settings → Secrets and variables → Actions → New repository secret`

| Секрет | Значение |
|---|---|
| `ANDROID_KEYSTORE_B64` | сам `.jks`, закодированный в base64 (одной строкой) |
| `ANDROID_KEYSTORE_PASSWORD` | пароль хранилища |
| `ANDROID_KEY_ALIAS` | алиас ключа (у нас `triada`) |
| `ANDROID_KEY_PASSWORD` | пароль ключа (обычно тот же, что у хранилища) |

Без `ANDROID_KEYSTORE_B64` сборка **падает с явной ошибкой** — это защита от
публикации неподписанного (неустанавливаемого) APK.

## Как выпустить новый ключ

Старый ключ (`app-v1.0.1`, `app-v1.1.0`) утёк в публичный репозиторий вместе с
паролями и считается **скомпрометированным**. Новый ключ генерируется так
(Windows, Git Bash; путь к `keytool` — из вашей Java):

```
"/c/Program Files/Java/jre-1.8/bin/keytool.exe" -genkeypair -v -keystore triada-release.jks -storetype JKS -alias triada -keyalg RSA -keysize 2048 -validity 10950 -dname "CN=Triada Mendeleeva, OU=Mafia Club, O=RHTU, L=Moscow, C=RU"
```

`keytool` спросит пароль — придумайте длинный и сохраните в менеджере паролей.
Затем получите base64 для секрета:

```
base64 -w0 triada-release.jks > keystore.b64
```

Содержимое `keystore.b64` — в секрет `ANDROID_KEYSTORE_B64`, пароль — в
`ANDROID_KEYSTORE_PASSWORD` и `ANDROID_KEY_PASSWORD`, `triada` — в `ANDROID_KEY_ALIAS`.
Сам `.jks` и `keystore.b64` держите **вне репозитория** (они в `.gitignore`),
но обязательно сохраните резервную копию: без этого файла обновления выпустить
нельзя, только новое приложение с нуля.

## Важно: смена ключа = переустановка у всех

Подпись меняется, поэтому обновление «поверх» не встанет. После первого релиза
с новым ключом участникам нужно **удалить приложение и поставить заново** с
`/app.php`. Об этом стоит написать в канал клуба.

## Локальная сборка

Для сборки на своей машине положите рядом:

* `mobile/android/keystore/triada-release.jks` — сам ключ;
* `mobile/android/keystore.properties`:

```
storeFile=keystore/triada-release.jks
storePassword=<пароль>
keyAlias=triada
keyPassword=<пароль>
```

Оба пути в `.gitignore` — в коммит они не попадут. Если файлов нет, релизная
сборка получится **неподписанной** и на телефон не установится.
