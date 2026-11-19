import type { Metadata } from "next";
import { Fraunces, Instrument_Sans, Geist, JetBrains_Mono } from "next/font/google";
import { I18nProvider } from "@/lib/i18n/I18nProvider";
import { resolveLocale } from "@/lib/i18n/server";
import "./globals.css";

const display = Fraunces({ subsets: ["latin"], axes: ["SOFT"], variable: "--f-display" });
const body = Instrument_Sans({ subsets: ["latin"], variable: "--f-body" });
const data = Geist({ subsets: ["latin"], variable: "--f-data" });
const code = JetBrains_Mono({ subsets: ["latin"], variable: "--f-code" });

export const metadata: Metadata = {
  title: "kassEvent",
  description: "Billetterie et invitations pour vos événements, en mobile money.",
};

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const locale = await resolveLocale();
  return (
    <html lang={locale} className={`${display.variable} ${body.variable} ${data.variable} ${code.variable}`}>
      <body>
        <I18nProvider initialLocale={locale}>{children}</I18nProvider>
      </body>
    </html>
  );
}
