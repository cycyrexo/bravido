import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'screens/home_screen.dart';

final navigatorKey = GlobalKey<NavigatorState>();

/// Charte BRAVIDO, dérivée du logo : sarcelle #339A99, jaune #FFCC00, orange #FF6600.
/// `vert` est une variante plus foncée du sarcelle : texte blanc lisible sur les boutons.
class Couleurs {
  static const vert = Color(0xFF1A8580);
  static const sarcelle = Color(0xFF339A99);
  static const vertClair = Color(0xFFE0F2F1);
  static const jaune = Color(0xFFFFCC00);
  static const orange = Color(0xFFFF6600);
  static const orangeClair = Color(0xFFFFEDE0);
  static const rouge = Color(0xFFE03131);
  static const rougeFonce = Color(0xFFC0261F);
  static const rougeClair = Color(0xFFFFE8E6);
  static const bleu = Color(0xFF2F6FD6);
  static const bleuClair = Color(0xFFE4EEFC);
  static const fond = Color(0xFFF4F7F8);
  static const gris = Color(0xFFE9EEEF);
  static const bord = Color(0xFFE3E9EA);
  static const texte = Color(0xFF16242A);
  static const texteSecondaire = Color(0xFF64737B);

  /// Ombre douce commune aux cartes.
  static const ombre = [BoxShadow(color: Color(0x0F0B2A33), blurRadius: 18, offset: Offset(0, 6))];
}

class BravidoApp extends StatelessWidget {
  const BravidoApp({super.key});

  @override
  Widget build(BuildContext context) {
    final schema = ColorScheme.fromSeed(
      seedColor: Couleurs.sarcelle,
      primary: Couleurs.vert,
      secondary: Couleurs.orange,
      surface: Colors.white,
      error: Couleurs.rouge,
    );

    return MaterialApp(
      title: 'BRAVIDO',
      navigatorKey: navigatorKey,
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: schema,
        scaffoldBackgroundColor: Couleurs.fond,
        splashFactory: InkSparkle.splashFactory,
        textTheme: Typography.blackMountainView.apply(bodyColor: Couleurs.texte, displayColor: Couleurs.texte),
        pageTransitionsTheme: const PageTransitionsTheme(builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        }),
        appBarTheme: const AppBarTheme(
          backgroundColor: Couleurs.fond,
          foregroundColor: Couleurs.texte,
          elevation: 0,
          scrolledUnderElevation: 0,
          systemOverlayStyle: SystemUiOverlayStyle.dark,
          titleTextStyle: TextStyle(color: Couleurs.texte, fontSize: 19, fontWeight: FontWeight.w800, letterSpacing: -0.2),
        ),
        cardTheme: CardThemeData(
          color: Colors.white,
          surfaceTintColor: Colors.transparent,
          elevation: 3,
          clipBehavior: Clip.antiAlias,
          shadowColor: const Color(0x260B2A33),
          margin: const EdgeInsets.symmetric(vertical: 6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        filledButtonTheme: FilledButtonThemeData(
          style: FilledButton.styleFrom(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
            textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
            side: const BorderSide(color: Couleurs.bord),
            textStyle: const TextStyle(fontWeight: FontWeight.w700),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        textButtonTheme: TextButtonThemeData(
          style: TextButton.styleFrom(textStyle: const TextStyle(fontWeight: FontWeight.w700)),
        ),
        chipTheme: ChipThemeData(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(99), side: const BorderSide(color: Couleurs.bord)),
          selectedColor: Couleurs.vert,
          backgroundColor: Colors.white,
          showCheckmark: false,
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
          labelStyle: const TextStyle(fontWeight: FontWeight.w700),
          secondaryLabelStyle: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          isDense: true,
          hintStyle: const TextStyle(color: Color(0xFF9AA6AC)),
          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Couleurs.bord)),
          enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Couleurs.bord)),
          focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Couleurs.sarcelle, width: 1.6)),
        ),
        bottomSheetTheme: const BottomSheetThemeData(backgroundColor: Colors.white, surfaceTintColor: Colors.transparent),
        dialogTheme: DialogThemeData(
          backgroundColor: Colors.white,
          surfaceTintColor: Colors.transparent,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        ),
        snackBarTheme: SnackBarThemeData(
          behavior: SnackBarBehavior.floating,
          backgroundColor: Couleurs.texte,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
        progressIndicatorTheme: const ProgressIndicatorThemeData(color: Couleurs.sarcelle),
      ),
      home: const HomeScreen(),
    );
  }
}
