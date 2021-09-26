use crate::{can_io, CanIo};
use crate::varint::{WriteProtocolVarIntExt, ReadProtocolVarIntExt};
use std::io::{Cursor, Error, ErrorKind};
use std::convert::TryFrom;

impl CanIo for Vec<String> {

    fn write(&self, vec: &mut Vec<u8>) {
        vec.write_var_u32((self.len()) as u32).unwrap();
        for str in self {
            str.write(vec);
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let mut src = Cursor::new(src);
        src.set_position(*offset as u64);
        let len = src.read_var_u32()?;
        *offset = src.position() as usize;
        let mut strs: Self = Vec::with_capacity(len.0 as usize);
        for _ in 0..len.0 {
            strs.push(String::read(src.clone().into_inner(), offset)?);
        }
        Ok(strs)
    }
}

can_io! {
    struct Text {
        pub text_type: Type,
        pub needs_translation: bool,
        pub source_name: String,
        pub message: String,
        pub parameters: Vec<String>,
        pub xuid: String,
        pub platform_chat_id: String,
    }
}

#[derive(Debug, Clone)]
pub enum Type {
    TextTypeRaw = 0x00,
    TextTypeChat = 0x01,
    TextTypeTranslation = 0x02,
    TextTypePopup = 0x03,
    TextTypeJukeboxPopup = 0x04,
    TextTypeTip = 0x05,
    TextTypeSystem = 0x06,
    TextTypeWhisper = 0x07,
    TextTypeAnnouncement = 0x08,
    TextTypeObject = 0x09,
    TextTypeObjectWhisper = 0x0A,
}

impl TryFrom<u8> for Type {
    type Error = Error;

    fn try_from(value: u8) -> Result<Self, Self::Error> {
        Ok(match value {
            0x00 => Self::TextTypeRaw,
            0x01 => Self::TextTypeChat,
            0x02 => Self::TextTypeTranslation,
            0x03 => Self::TextTypePopup,
            0x04 => Self::TextTypeJukeboxPopup,
            0x05 => Self::TextTypeTip,
            0x06 => Self::TextTypeSystem,
            0x07 => Self::TextTypeWhisper,
            0x08 => Self::TextTypeAnnouncement,
            0x09 => Self::TextTypeObject,
            0x0A => Self::TextTypeObjectWhisper,
            _ => return Err(Error::new(ErrorKind::InvalidData, "Invalid PlayStatus Recieved!"))
        })
    }
}

impl CanIo for Type {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as u8).write(vec);
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        Ok(Self::try_from(u8::read(src, offset)?)?)
    }
}