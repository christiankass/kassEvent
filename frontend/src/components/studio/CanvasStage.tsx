"use client";

import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from "react";
import Konva from "konva";
import { Ellipse, Group, Image as KonvaImage, Layer, Line, Path, Rect, Shape, Stage, Star, Text, Transformer } from "react-konva";
import {
  SINGLE_WEIGHT,
  isDynamic,
  isTextual,
  type CanvasDoc,
  type CanvasElement,
  type CanvasFont,
  type FieldName,
  type TextElement,
  type ShapeElement,
} from "@/lib/canvas";
import { FILLED_ICONS, ICON_PATHS } from "@/lib/canvas-icons";

export type StageHandle = {
  /** Image de la page au format exact ; `withDynamic` false : sans QR code, champs ni emplacements vides. */
  exportBlob: (type: "image/jpeg" | "image/png", withDynamic: boolean) => Promise<Blob>;
};

type Props = {
  doc: CanvasDoc;
  scale: number;
  fonts: Record<CanvasFont, string>;
  images: Record<string, HTMLImageElement | undefined>;
  samples: Record<FieldName, string>;
  photoLabel: string;
  selectedId?: string | null;
  interactive?: boolean;
  onSelect?: (id: string | null) => void;
  onChange?: (element: CanvasElement) => void;
  onEditText?: (id: string) => void;
};

/** Hauteur du cadre ajustée au texte : aucune ligne n'est coupée, sur la page comme à l'export. */
export function fitText(element: TextElement, fonts: Record<CanvasFont, string>): TextElement {
  const measure = new Konva.Text({
    text: element.text,
    width: element.width,
    fontFamily: fonts[element.font],
    fontSize: element.fontSize,
    fontStyle: [element.italic && "italic", element.bold && "bold"].filter(Boolean).join(" ") || "normal",
    lineHeight: element.lineHeight ?? 1.2,
    letterSpacing: element.letterSpacing ?? 0,
    wrap: "word",
  });
  const height = Math.ceil(measure.height());
  measure.destroy();
  return height === element.height ? element : { ...element, height };
}

/** Textes d'une page ajustés à leur cadre (modèle choisi, format changé). */
export function fitTexts(doc: CanvasDoc, fonts: Record<CanvasFont, string>): CanvasDoc {
  return { ...doc, elements: doc.elements.map((element) => (element.type === "text" ? fitText(element, fonts) : element)) };
}

/** Distance d'aimantation au centre de la page, en pixels d'écran. */
const SNAP = 8;

/** Dessin d'un document du studio ; éditable (déplacer, redimensionner, tourner) quand `interactive`. */
export const CanvasStage = forwardRef<StageHandle, Props>(function CanvasStage(
  { doc, scale, fonts, images, samples, photoLabel, selectedId = null, interactive = false, onSelect, onChange, onEditText },
  ref,
) {
  const stageRef = useRef<Konva.Stage>(null);
  const transformerRef = useRef<Konva.Transformer>(null);
  const [guides, setGuides] = useState<{ x: boolean; y: boolean }>({ x: false, y: false });
  const selected = doc.elements.find((element) => element.id === selectedId) ?? null;

  useEffect(() => {
    const transformer = transformerRef.current;
    if (!transformer) return;
    const node = selectedId ? stageRef.current?.findOne(`#${selectedId}`) : undefined;
    transformer.nodes(node ? [node] : []);
    transformer.getLayer()?.batchDraw();
  }, [selectedId, doc]);

  useImperativeHandle(ref, () => ({
    async exportBlob(type, withDynamic) {
      const stage = stageRef.current!;
      const hidden = [
        ...(transformerRef.current ? [transformerRef.current] : []),
        ...stage.find(".guide"),
        ...stage.find(withDynamic ? ".placeholder" : ".dynamic"),
      ];
      hidden.forEach((node) => node.hide());
      try {
        let canvas = stage.toCanvas({ pixelRatio: doc.width / stage.width() });
        // Arrondis : la page doit faire exactement le format attendu par le serveur.
        if (canvas.width !== doc.width || canvas.height !== doc.height) {
          const exact = document.createElement("canvas");
          exact.width = doc.width;
          exact.height = doc.height;
          exact.getContext("2d")!.drawImage(canvas, 0, 0, doc.width, doc.height);
          canvas = exact;
        }
        return await new Promise<Blob>((resolve, reject) =>
          canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("export"))), type, 0.92),
        );
      } finally {
        hidden.forEach((node) => node.show());
      }
    },
  }));

  function commit(node: Konva.Node, element: CanvasElement) {
    const scaleX = node.scaleX();
    const scaleY = node.scaleY();
    node.scale({ x: 1, y: 1 });
    let next: CanvasElement = {
      ...element,
      x: round(node.x()),
      y: round(node.y()),
      rotation: round(node.rotation()),
      width: Math.max(4, round(element.width * scaleX)),
      height: Math.max(4, round(element.height * scaleY)),
    };
    // Coin tiré sur un texte : la police grandit avec le cadre, comme sur une affiche.
    if (isTextual(next) && Math.abs(scaleX - scaleY) < 0.001 && scaleX !== 1) {
      next = { ...next, fontSize: Math.max(6, round(next.fontSize * scaleY)) };
    }
    onChange?.(next);
  }

  function snap(node: Konva.Node, element: CanvasElement) {
    if (element.rotation !== 0) return;
    const centerX = node.x() + element.width / 2;
    const centerY = node.y() + element.height / 2;
    const x = Math.abs(centerX - doc.width / 2) < SNAP / scale;
    const y = Math.abs(centerY - doc.height / 2) < SNAP / scale;
    if (x) node.x(doc.width / 2 - element.width / 2);
    if (y) node.y(doc.height / 2 - element.height / 2);
    if (x !== guides.x || y !== guides.y) setGuides({ x, y });
  }

  return (
    <Stage
      ref={stageRef}
      width={doc.width * scale}
      height={doc.height * scale}
      scaleX={scale}
      scaleY={scale}
      listening={interactive}
      onMouseDown={(event) => {
        if (event.target === event.target.getStage() || event.target.name() === "background") onSelect?.(null);
      }}
      onTouchStart={(event) => {
        if (event.target === event.target.getStage() || event.target.name() === "background") onSelect?.(null);
      }}
    >
      <Layer>
        <Rect name="background" width={doc.width} height={doc.height} fill={doc.background.color} />
        {doc.background.image && images[doc.background.image] && (
          <KonvaImage name="background" image={images[doc.background.image]} {...cover(images[doc.background.image]!, doc.width, doc.height)} />
        )}
        {doc.elements.map((element) => (
          <Group
            key={element.id}
            id={element.id}
            name={isDynamic(element) ? "dynamic" : undefined}
            x={element.x}
            y={element.y}
            rotation={element.rotation}
            opacity={element.opacity ?? 1}
            draggable={interactive}
            onMouseDown={() => onSelect?.(element.id)}
            onTap={() => onSelect?.(element.id)}
            onDblClick={() => isTextual(element) && onEditText?.(element.id)}
            onDblTap={() => isTextual(element) && onEditText?.(element.id)}
            dragBoundFunc={element.type === "qr" ? (position) => clampInside(position, element, doc, scale) : undefined}
            onDragMove={(event) => snap(event.target, element)}
            onDragEnd={(event) => {
              setGuides({ x: false, y: false });
              commit(event.target, element);
            }}
            onTransformEnd={(event) => commit(event.target, element)}
          >
            {/* Zone de prise : tout le cadre, même pour une ligne fine ou un texte court. */}
            <Rect width={element.width} height={element.height} fill="rgba(0,0,0,0)" />
            <ElementShape element={element} fonts={fonts} images={images} samples={samples} photoLabel={photoLabel} tint={doc.background.color} />
          </Group>
        ))}
        {guides.x && <Line name="guide" points={[doc.width / 2, 0, doc.width / 2, doc.height]} stroke="#F72585" strokeWidth={1 / scale} dash={[6 / scale, 4 / scale]} />}
        {guides.y && <Line name="guide" points={[0, doc.height / 2, doc.width, doc.height / 2]} stroke="#F72585" strokeWidth={1 / scale} dash={[6 / scale, 4 / scale]} />}
        {interactive && (
          <Transformer
            ref={transformerRef}
            rotateEnabled={selected?.type !== "qr"}
            keepRatio={selected?.type === "qr" || selected?.type === "image" || selected?.type === "icon"}
            enabledAnchors={
              selected?.type === "qr"
                ? ["top-left", "top-right", "bottom-left", "bottom-right"]
                : selected && isTextual(selected)
                  ? ["top-left", "top-right", "bottom-left", "bottom-right", "middle-left", "middle-right"]
                  : undefined
            }
            rotationSnaps={[0, 45, 90, 135, 180, 225, 270, 315]}
            anchorSize={12}
            anchorCornerRadius={6}
            borderStroke="#0B3B3C"
            anchorStroke="#0B3B3C"
            anchorFill="#F2B705"
            boundBoxFunc={(previous, next) => (Math.abs(next.width) < 8 || Math.abs(next.height) < 8 ? previous : next)}
          />
        )}
      </Layer>
    </Stage>
  );
});

function ElementShape({
  element,
  fonts,
  images,
  samples,
  photoLabel,
  tint,
}: Pick<Props, "fonts" | "images" | "samples" | "photoLabel"> & { element: CanvasElement; tint: string }) {
  const { width, height } = element;
  switch (element.type) {
    case "text":
    case "field":
      return (
        <Text
          width={width}
          height={height}
          text={element.type === "text" ? element.text : samples[element.field]}
          fontFamily={fonts[element.font]}
          fontSize={element.fontSize}
          fontStyle={[element.italic && element.type === "text" && "italic", element.bold && (element.type === "text" || !SINGLE_WEIGHT.has(element.font)) && "bold"].filter(Boolean).join(" ") || "normal"}
          fill={element.fill}
          align={element.align ?? "left"}
          lineHeight={element.lineHeight ?? 1.2}
          letterSpacing={element.type === "text" ? (element.letterSpacing ?? 0) : 0}
          wrap={element.type === "field" ? "none" : "word"}
          ellipsis={element.type === "field"}
        />
      );
    case "rect":
    case "ellipse":
    case "star":
    case "line":
      return <ShapeNode element={element} />;
    case "image": {
      const image = element.asset ? images[element.asset] : undefined;
      if (image) {
        return element.fit === "contain" ? (
          <KonvaImage image={image} {...contain(image, width, height)} cornerRadius={element.cornerRadius ?? 0} />
        ) : (
          <KonvaImage image={image} width={width} height={height} cornerRadius={element.cornerRadius ?? 0} crop={cover(image, width, height).crop} />
        );
      }
      return <PhotoPlaceholder width={width} height={height} radius={element.cornerRadius ?? 0} tint={tint} label={element.asset ? null : `＋ ${element.name || photoLabel}`} />;
    }
    case "icon": {
      const filled = FILLED_ICONS.has(element.icon);
      return (
        <Path
          data={ICON_PATHS[element.icon]}
          scaleX={width / 24}
          scaleY={height / 24}
          fill={filled ? element.fill : undefined}
          stroke={filled ? undefined : element.fill}
          strokeWidth={2}
          lineCap="round"
          lineJoin="round"
        />
      );
    }
    case "qr":
      return <QrSample size={width} />;
  }
}

/** Mélange de deux couleurs « #RRGGBB » (`amount` de la seconde). */
function mix(color: string, other: string, amount: number): string {
  const channels = (hex: string) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) || 0);
  const [a, b] = [channels(/^#[0-9a-f]{6}$/i.test(color) ? color : "#FFFFFF"), channels(other)];
  return `#${a.map((value, i) => Math.round(value + (b[i] - value) * amount).toString(16).padStart(2, "0")).join("")}`;
}

/**
 * Emplacement photo vide : un aplat dans la teinte du fond, à peine plus clair ou plus foncé, pour
 * que le modèle garde son allure tant que la photo n'est pas posée. Absent du billet enregistré ;
 * sur une photo introuvable (`label` null), seul l'aplat reste.
 */
function PhotoPlaceholder({ width, height, radius, tint, label }: { width: number; height: number; radius: number; tint: string; label: string | null }) {
  const size = Math.min(width, height);
  const channels = [1, 3, 5].map((i) => parseInt(tint.slice(i, i + 2), 16) || 255);
  const dark = channels[0] * 0.3 + channels[1] * 0.59 + channels[2] * 0.11 < 128;
  const toward = dark ? "#FFFFFF" : "#000000";
  const ink = mix(tint, toward, 0.55);
  return (
    <Group name={label ? "placeholder" : undefined}>
      <Rect
        width={width}
        height={height}
        cornerRadius={radius}
        fillLinearGradientStartPoint={{ x: 0, y: 0 }}
        fillLinearGradientEndPoint={{ x: width, y: height }}
        fillLinearGradientColorStops={[0, mix(tint, toward, 0.1), 1, mix(tint, toward, 0.2)]}
      />
      {label && (
        <>
          <Rect width={width} height={height} cornerRadius={radius} stroke={ink} opacity={0.5} strokeWidth={Math.max(2, size / 200)} dash={[12, 10]} />
          <Text width={width} height={height} text={label} align="center" verticalAlign="middle" fontSize={Math.max(18, size / 13)} fill={ink} fontStyle="bold" padding={16} />
        </>
      )}
    </Group>
  );
}

function ShapeNode({ element }: { element: ShapeElement }) {
  const { width, height } = element;
  // Seconde couleur seule : fondu du transparent vers elle (photo qui s'efface dans le fond).
  const from = element.fill ?? (element.fill2 ? `${element.fill2}00` : null);
  const fill = element.fill2 && from
    ? {
        fillLinearGradientStartPoint: { x: 0, y: element.type === "ellipse" || element.type === "star" ? -height / 2 : 0 },
        fillLinearGradientEndPoint: { x: 0, y: element.type === "ellipse" || element.type === "star" ? height / 2 : height },
        fillLinearGradientColorStops: [0, from, 1, element.fill2],
      }
    : { fill: element.fill ?? undefined };
  const stroke = { stroke: element.stroke ?? undefined, strokeWidth: element.stroke ? (element.strokeWidth ?? 2) : 0 };

  switch (element.type) {
    case "rect":
      return <Rect width={width} height={height} cornerRadius={element.cornerRadius ?? 0} {...fill} {...stroke} />;
    case "ellipse":
      return <Ellipse x={width / 2} y={height / 2} radiusX={width / 2} radiusY={height / 2} {...fill} {...stroke} />;
    case "star":
      return (
        <Star
          x={width / 2}
          y={height / 2}
          numPoints={5}
          outerRadius={width / 2}
          innerRadius={width / 4.4}
          scaleY={height / width}
          {...fill}
          {...stroke}
        />
      );
    case "line":
      return (
        <Line
          points={[0, height / 2, width, height / 2]}
          stroke={element.stroke ?? element.fill ?? "#0E1B1C"}
          strokeWidth={element.strokeWidth || 4}
          lineCap="round"
        />
      );
  }
}

/** QR code d'exemple : le vrai est ajouté pour chaque billet, toujours noir sur blanc. */
function QrSample({ size }: { size: number }) {
  const ref = useRef<Konva.Shape>(null);
  // Mis en cache : pas 600 petits carrés redessinés à chaque image d'un glisser.
  useEffect(() => {
    ref.current?.cache();
  }, [size]);
  return (
    <Shape
      ref={ref}
      width={size}
      height={size}
      sceneFunc={(context) => {
        const modules = 25;
        const cell = size / (modules + 4);
        context.fillStyle = "#FFFFFF";
        context.fillRect(0, 0, size, size);
        context.fillStyle = "#0E1B1C";
        const finder = (col: number, row: number) => {
          context.fillRect((col + 2) * cell, (row + 2) * cell, 7 * cell, 7 * cell);
          context.fillStyle = "#FFFFFF";
          context.fillRect((col + 3) * cell, (row + 3) * cell, 5 * cell, 5 * cell);
          context.fillStyle = "#0E1B1C";
          context.fillRect((col + 4) * cell, (row + 4) * cell, 3 * cell, 3 * cell);
        };
        finder(0, 0);
        finder(modules - 7, 0);
        finder(0, modules - 7);
        for (let row = 0; row < modules; row++) {
          for (let col = 0; col < modules; col++) {
            const inFinder = (row < 8 && col < 8) || (row < 8 && col >= modules - 8) || (row >= modules - 8 && col < 8);
            if (!inFinder && (row * 7 + col * 13 + row * col) % 5 < 2) {
              context.fillRect((col + 2) * cell, (row + 2) * cell, cell, cell);
            }
          }
        }
      }}
    />
  );
}

/** Cadrage « contenir » : l'image entière, centrée dans le cadre. */
function contain(image: HTMLImageElement, width: number, height: number) {
  const ratio = Math.min(width / image.naturalWidth, height / image.naturalHeight);
  const w = image.naturalWidth * ratio;
  const h = image.naturalHeight * ratio;
  return { x: (width - w) / 2, y: (height - h) / 2, width: w, height: h };
}

/** Recadrage « remplir » : l'image couvre tout le cadre, centrée. */
function cover(image: HTMLImageElement, width: number, height: number) {
  const ratio = Math.max(width / image.naturalWidth, height / image.naturalHeight);
  const cropWidth = width / ratio;
  const cropHeight = height / ratio;
  return {
    width,
    height,
    crop: { x: (image.naturalWidth - cropWidth) / 2, y: (image.naturalHeight - cropHeight) / 2, width: cropWidth, height: cropHeight },
  };
}

function clampInside(position: { x: number; y: number }, element: CanvasElement, doc: CanvasDoc, scale: number) {
  return {
    x: Math.min(Math.max(position.x, 0), (doc.width - element.width) * scale),
    y: Math.min(Math.max(position.y, 0), (doc.height - element.height) * scale),
  };
}

const round = (value: number) => Math.round(value * 100) / 100;
