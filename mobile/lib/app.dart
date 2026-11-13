import 'dart:ui' as ui;

import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter/services.dart';

import 'core/apparence.dart';
import 'core/hors_ligne.dart';
import 'widgets/bandeau_hors_ligne.dart';
import 'core/langue.dart';
import 'screens/home_screen.dart';

final navigatorKey = GlobalKey<NavigatorState>();

/// Charte BRAVIDO, dérivée du logo : sarcelle #339A99, jaune #FFCC00, orange #FF6600.
/// `vert` est une variante plus foncée du sarcelle : texte blanc lisible sur les boutons.
///
/// Les couleurs d'accent sont constantes ; les couleurs de surface, de texte et les teintes pastel
/// dépendent du thème (clair/sombre) : ce sont des accesseurs lus à chaque construction de widget.
/// `sombre` est mis à jour par [BravidoApp] avant tout rafraîchissement de l'interface.
class Couleurs {
  /// Thème sombre actif (résolu : « système » déjà converti).
  static bool sombre = false;

  static const vert = Color(0xFF167A74);
  static const sarcelle = Color(0xFF339A99);
  static const jaune = Color(0xFFFFCC00);
  static const orange = Color(0xFFFF6600);
  static const rouge = Color(0xFFE03131);
  static const rougeFonce = Color(0xFFC0261F);
  static const bleu = Color(0xFF2F6FD6);

  // Teintes pastel (fonds de pastilles, d'icônes, d'alertes)
  static Color get vertClair => sombre ? const Color(0xFF16383A) : const Color(0xFFE0F2F1);
  static Color get orangeClair => sombre ? const Color(0xFF3F2A1B) : const Color(0xFFFFEDE0);
  static Color get rougeClair => sombre ? const Color(0xFF45221F) : const Color(0xFFFFE8E6);
  static Color get bleuClair => sombre ? const Color(0xFF1D2F4A) : const Color(0xFFE4EEFC);
  static Color get rose => sombre ? const Color(0xFF47213A) : const Color(0xFFFBE4F0);

  // Surfaces et textes
  static Color get fond => sombre ? const Color(0xFF0F1619) : const Color(0xFFF4F7F8);
  static Color get surface => sombre ? const Color(0xFF182226) : Colors.white;
  static Color get gris => sombre ? const Color(0xFF243137) : const Color(0xFFE9EEEF);
  static Color get bord => sombre ? const Color(0xFF2E3D44) : const Color(0xFFE3E9EA);
  static Color get texte => sombre ? const Color(0xFFE8EEF0) : const Color(0xFF16242A);
  static Color get texteSecondaire => sombre ? const Color(0xFFA7B6BD) : const Color(0xFF5A6870);

  // Encres foncées (clair) / claires (sombre) sur les fonds pastel : contraste ≥ 4,5:1
  static Color get encreVerte => sombre ? const Color(0xFF7FD6CF) : const Color(0xFF146A66);
  static Color get encreOrange => sombre ? const Color(0xFFFFB27A) : const Color(0xFFA84300);
  static Color get encreRouge => sombre ? const Color(0xFFFF9A94) : rougeFonce;
  static Color get encreGrise => sombre ? const Color(0xFFB8C4CA) : const Color(0xFF55646C);

  /// Texte d'accent (liens, boutons texte) lisible sur la surface courante.
  static Color get accent => sombre ? const Color(0xFF5CC8BE) : vert;

  static Color get bordRouge => sombre ? const Color(0xFF7A3A36) : const Color(0xFFF3C4C0);

  /// Ombre douce commune aux cartes (aucune en thème sombre).
  static List<BoxShadow> get ombre =>
      sombre ? const [] : const [BoxShadow(color: Color(0x0F0B2A33), blurRadius: 18, offset: Offset(0, 6))];
}

class BravidoApp extends StatefulWidget {
  const BravidoApp({super.key});

  @override
  State<BravidoApp> createState() => _BravidoAppState();
}

class _BravidoAppState extends State<BravidoApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    Apparence.instance.addListener(_changement);
    Langue.instance.addListener(_changement);
    _synchroniser();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    Apparence.instance.removeListener(_changement);
    Langue.instance.removeListener(_changement);
    super.dispose();
  }

  @override
  void didChangePlatformBrightness() {
    if (Apparence.instance.theme == ThemeMode.system) _changement();
  }

  void _synchroniser() {
    final mode = Apparence.instance.theme;
    Couleurs.sombre = mode == ThemeMode.dark ||
        (mode == ThemeMode.system && ui.PlatformDispatcher.instance.platformBrightness == Brightness.dark);
  }

  /// Thème, langue ou taille changés : les couleurs et textes lus hors de l'arbre d'héritage
  /// (`Couleurs.*`, `tr()`) imposent de reconstruire tous les widgets (sans perdre la navigation).
  void _changement() {
    setState(_synchroniser);
    WidgetsBinding.instance.reassembleApplication();
  }

  static ThemeData _theme(Brightness b) {
    final sombre = b == Brightness.dark;
    final fond = sombre ? const Color(0xFF0F1619) : const Color(0xFFF4F7F8);
    final surface = sombre ? const Color(0xFF182226) : Colors.white;
    final texte = sombre ? const Color(0xFFE8EEF0) : const Color(0xFF16242A);
    final bord = sombre ? const Color(0xFF2E3D44) : const Color(0xFFE3E9EA);
    final schema = sombre
        ? ColorScheme.fromSeed(
            seedColor: Couleurs.sarcelle,
            brightness: Brightness.dark,
            primary: const Color(0xFF5CC8BE),
            onPrimary: const Color(0xFF00201E),
            secondary: const Color(0xFFFF8A3D),
            surface: surface,
            onSurface: texte,
            onSurfaceVariant: const Color(0xFFA7B6BD),
            error: const Color(0xFFFF8A80),
            outlineVariant: bord,
          )
        : ColorScheme.fromSeed(
            seedColor: Couleurs.sarcelle,
            primary: Couleurs.vert,
            secondary: Couleurs.orange,
            surface: surface,
            error: Couleurs.rouge,
            onSurfaceVariant: const Color(0xFF5A6870),
          );

    return ThemeData(
      useMaterial3: true,
      brightness: b,
      colorScheme: schema,
      scaffoldBackgroundColor: fond,
      splashFactory: InkSparkle.splashFactory,
      textTheme: (sombre ? Typography.whiteMountainView : Typography.blackMountainView).apply(bodyColor: texte, displayColor: texte),
      pageTransitionsTheme: const PageTransitionsTheme(builders: {
        TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
        TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
      }),
      appBarTheme: AppBarTheme(
        backgroundColor: fond,
        foregroundColor: texte,
        elevation: 0,
        scrolledUnderElevation: 0,
        systemOverlayStyle: sombre ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
        titleTextStyle: TextStyle(color: texte, fontSize: 19, fontWeight: FontWeight.w800, letterSpacing: -0.2),
      ),
      cardTheme: CardThemeData(
        color: surface,
        surfaceTintColor: Colors.transparent,
        elevation: sombre ? 0 : 3,
        clipBehavior: Clip.antiAlias,
        shadowColor: const Color(0x260B2A33),
        margin: const EdgeInsets.symmetric(vertical: 6),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: sombre ? BorderSide(color: bord) : BorderSide.none,
        ),
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
          side: BorderSide(color: bord),
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(textStyle: const TextStyle(fontWeight: FontWeight.w700)),
      ),
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(99), side: BorderSide(color: bord)),
        selectedColor: Couleurs.vert,
        backgroundColor: surface,
        showCheckmark: false,
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
        labelStyle: TextStyle(fontWeight: FontWeight.w700, color: texte),
        secondaryLabelStyle: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        isDense: true,
        hintStyle: TextStyle(color: sombre ? const Color(0xFF8FA0A8) : const Color(0xFF7D8B92)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: bord)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: bord)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Couleurs.sarcelle, width: 1.6)),
      ),
      bottomSheetTheme: BottomSheetThemeData(backgroundColor: surface, surfaceTintColor: Colors.transparent),
      dialogTheme: DialogThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: sombre ? const Color(0xFFE8EEF0) : const Color(0xFF16242A),
        contentTextStyle: TextStyle(color: sombre ? const Color(0xFF16242A) : Colors.white),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? (sombre ? const Color(0xFF00201E) : Colors.white) : null),
        trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? schema.primary : null),
      ),
      dividerTheme: DividerThemeData(color: bord),
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: Couleurs.sarcelle),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'BRAVIDO',
      navigatorKey: navigatorKey,
      locale: Locale(Langue.code),
      supportedLocales: const [Locale('fr'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      debugShowCheckedModeBanner: false,
      theme: _theme(Brightness.light),
      darkTheme: _theme(Brightness.dark),
      themeMode: Apparence.instance.theme,
      // Taille du texte choisie dans les réglages, appliquée par-dessus l'échelle du système
      builder: (context, child) {
        final mq = MediaQuery.of(context);
        final systeme = mq.textScaler.scale(10) / 10;
        return MediaQuery(
          data: mq.copyWith(textScaler: TextScaler.linear((systeme * Apparence.instance.tailleTexte).clamp(0.8, 1.8))),
          // Structure constante (le Navigator ne doit jamais être recréé) : bandeau « Hors ligne » (F20) au-dessus de l'appli
          child: ValueListenableBuilder<DateTime?>(
            valueListenable: HorsLigne.instance.donneesLocalesDu,
            builder: (context, horsLigne, _) => Column(children: [
              const BandeauHorsLigne(),
              Expanded(child: MediaQuery.removePadding(context: context, removeTop: horsLigne != null, child: child!)),
            ]),
          ),
        );
      },
      home: const HomeScreen(),
    );
  }
}
