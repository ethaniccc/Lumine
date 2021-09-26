use crate::varint::{ReadProtocolVarIntExt, WriteProtocolVarIntExt};
use crate::{can_io, CanIo};
use std::io::Cursor;

can_io! {
    struct BehaviourPackInfo {
        pub uuid: String,
        pub version: String,
        pub size: u64,
        pub content_key: String,
        pub subpack_name: String,
        pub content_identity: String,
        pub has_scripts: bool,
    }
}

can_io! {
    struct TexturePackInfo {
        pub uuid: String,
        pub version: String,
        pub size: u64,
        pub content_key: String,
        pub subpack_name: String,
        pub content_identity: String,
        pub has_scripts: bool,
        pub rtx_enabled: bool,
    }
}

can_io! {
    struct StackResourcePack {
        pub uuid: String,
        pub version: String,
        pub subpack_name: String,
    }
}

impl CanIo for Vec<BehaviourPackInfo> {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.len() as u16).write(vec);
        for bpinfo in self {
            bpinfo.write(vec);
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let len = u16::read(src, offset)?;
        let mut vec = Vec::with_capacity(len as usize);
        for _ in 0..len {
            vec.push(BehaviourPackInfo::read(src, offset)?)
        }
        Ok(vec)
    }
}

impl CanIo for Vec<TexturePackInfo> {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.len() as u16).write(vec);
        for tpinfo in self {
            tpinfo.write(vec);
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let len = u16::read(src, offset)?;
        let mut vec = Vec::with_capacity(len as usize);
        for _ in 0..len {
            vec.push(TexturePackInfo::read(src, offset)?)
        }
        Ok(vec)
    }
}

impl CanIo for Vec<StackResourcePack> {
    fn write(&self, vec: &mut Vec<u8>) {
        vec.write_var_u32(self.len() as u32).unwrap();
        for stack_pack in self {
            stack_pack.write(vec);
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        let mut c = Cursor::new(src);
        c.set_position(*offset as u64);
        let len = c.read_var_u32()?;
        *offset = c.position() as usize;
        let mut vec = Vec::with_capacity(len.0 as usize);
        for _ in 0..len.0 {
            vec.push(StackResourcePack::read(src, offset)?);
        }
        Ok(vec)
    }
}
