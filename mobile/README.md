# Natak phone apps

A thin native shell (Capacitor) that opens the live app at https://aidadaji.com/natak/.
All screens, accounts, coins and video stay on the server, so a web update reaches the apps at once.

- Android: the "Android test build" workflow builds a debug APK and attaches it to the `android-test` release.
- iPhone: `npm install && npx cap add ios` on a Mac with Xcode, signed with an Apple Developer account.
