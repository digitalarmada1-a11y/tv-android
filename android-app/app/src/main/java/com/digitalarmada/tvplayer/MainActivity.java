package com.digitalarmada.tvplayer;

import android.annotation.SuppressLint;
import android.os.Bundle;
import android.view.KeyEvent;
import android.view.View;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import androidx.appcompat.app.AppCompatActivity;

public class MainActivity extends AppCompatActivity {

    private WebView webView;
    private static final String APP_URL = "https://digitalarmada1-a11y.github.io/tv-android/tv.html";

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Ocultar barras de sistema para modo TV inmersivo
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_FULLSCREEN |
                View.SYSTEM_UI_FLAG_HIDE_NAVIGATION |
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
        );

        webView = new WebView(this);
        setContentView(webView);

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);

        // Optimizar para video
        webView.setWebChromeClient(new WebChromeClient());
        webView.setWebViewClient(new WebViewClient());

        webView.loadUrl(APP_URL);
    }

    @Override
    public void onBackPressed() {
        // Delegate back navigation to JavaScript.
        // JS returns "exit" if the user is on the home screen (should close app),
        // or "handled" if it navigated back internally (player->detail->home).
        webView.evaluateJavascript(
            "(function(){ return window.__handleBackPress ? window.__handleBackPress() : 'exit'; })()",
            value -> {
                if ("\"exit\"".equals(value)) {
                    // User is on home screen, close the app
                    MainActivity.super.onBackPressed();
                }
                // Otherwise JS already handled the navigation
            }
        );
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        // Let the WebView handle D-pad and media keys
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            onBackPressed();
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }
}
