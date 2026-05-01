import java.awt.RenderingHints
import java.awt.image.BufferedImage
import javax.imageio.ImageIO

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val launcherSourcePng = layout.projectDirectory.file("src/main/res/drawable-nodpi/app_logo.png")
val launcherSourceRelativePath = "src/main/res/drawable-nodpi/app_logo.png"
val generatedLauncherResDir = layout.buildDirectory.dir("generated/res/customLauncher")

val generateLauncherPngResources by tasks.registering {
    inputs.file(launcherSourcePng)
    outputs.dir(generatedLauncherResDir)

    doLast {
        val sourceImage = ImageIO.read(launcherSourcePng.asFile)
            ?: error(
                "Unable to read launcher PNG at $launcherSourceRelativePath. " +
                    "Make sure the file exists and is a valid PNG image."
            )
        val outputRoot = generatedLauncherResDir.get().asFile
        val densities = mapOf(
            "mipmap-mdpi" to 48,
            "mipmap-hdpi" to 72,
            "mipmap-xhdpi" to 96,
            "mipmap-xxhdpi" to 144,
            "mipmap-xxxhdpi" to 192
        )

        densities.forEach { (directoryName, iconSize) ->
            val scaledImage = BufferedImage(iconSize, iconSize, BufferedImage.TYPE_INT_ARGB)
            val graphics = scaledImage.createGraphics()

            graphics.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BICUBIC)
            graphics.setRenderingHint(RenderingHints.KEY_RENDERING, RenderingHints.VALUE_RENDER_QUALITY)
            graphics.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON)
            graphics.drawImage(sourceImage, 0, 0, iconSize, iconSize, null)
            graphics.dispose()

            val outputDir = outputRoot.resolve(directoryName)
            outputDir.mkdirs()

            ImageIO.write(scaledImage, "png", outputDir.resolve("ic_launcher.png"))
            ImageIO.write(scaledImage, "png", outputDir.resolve("ic_launcher_round.png"))
        }
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

    sourceSets {
        getByName("main").res.srcDir(generateLauncherPngResources)
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.activity:activity-ktx:1.9.0")
    implementation("androidx.webkit:webkit:1.11.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")
}
