apply(plugin = "com.android.application")
apply(plugin = "org.jetbrains.kotlin.android")

val launcherSourcePng = layout.projectDirectory.file("src/main/res/drawable-nodpi/app_logo.png")
val generatedLauncherResDir = layout.buildDirectory.dir("generated/res/customLauncher")

val generateLauncherPngResources by tasks.registering {
    inputs.file(launcherSourcePng)
    outputs.dir(generatedLauncherResDir)

    doLast {
        val mipmapDir = generatedLauncherResDir.get().asFile.resolve("mipmap")
        mipmapDir.mkdirs()

        launcherSourcePng.asFile.copyTo(mipmapDir.resolve("ic_launcher.png"), overwrite = true)
        launcherSourcePng.asFile.copyTo(mipmapDir.resolve("ic_launcher_round.png"), overwrite = true)
    }
}

android {
    namespace = "com.copragym.mobile"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.copragym.mobile"
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"
        buildConfigField("String", "PORTAL_URL", "\"https://al-idrisi-gym.great-site.net/admin/member_portal.php\"")
        buildConfigField("String", "PORTAL_API_URL", "\"https://al-idrisi-gym.great-site.net/admin/member_portal_mobile_api.php\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    sourceSets.getByName("main").res.srcDir(generatedLauncherResDir)
}

tasks.named("preBuild") {
    dependsOn(generateLauncherPngResources)
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.activity:activity-ktx:1.9.0")
    implementation("androidx.webkit:webkit:1.11.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
}
